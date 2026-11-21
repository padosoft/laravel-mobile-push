<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Carbon\CarbonImmutable;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\Devices\DeviceState;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Psr\Clock\ClockInterface;

/**
 * The explicit environment probe (docs/PLAN.md section 4.4): the ONLY place where the package ever tries a device
 * token on the other APNs environment. It is an operator action, never part of a send.
 *
 * For each quarantined APNs device token it sends ONE silent background push, through the connection of the SAME app
 * (same scope, same bundle id) on the OTHER environment:
 *  - Accepted: the registration is re-labelled (environment and binding) and reactivated under a new generation;
 *  - BadDeviceToken again: the device stays quarantined;
 *  - a connection that DECLARES its key restricted to one environment (`connections.<name>.key_environments`) is
 *    refused up front, before any request, when the probe would use it on the other one (ProbeOutcome::KeyRestricted);
 *  - a refusal of the credential (a key limited to one environment answers this way when nothing was declared): the
 *    WHOLE run stops;
 *  - anything else: the device is left untouched.
 *
 * Devices are probed one at a time, so a stop is immediate and a rejected token (which counts against Apple's error
 * budget) is never followed by a hundred more. The execution mode and the safe-mode guard are asked first
 * (OperationKind::Probe): in simulation nothing is sent.
 */
final class EnvironmentProber
{
    /** Answers that mean "the credential is the problem": continuing would only repeat it. */
    private const STOPS = [
        ReasonCode::AuthTokenInvalid, ReasonCode::AuthTokenExpired, ReasonCode::AuthTokenThrottled,
        ReasonCode::AuthCertificateInvalid, ReasonCode::AuthForbidden, ReasonCode::AuthThirdParty, ReasonCode::CircuitOpen,
    ];

    /** @var array<string, list<BindingRef>> */
    private array $apnsBindings = [];

    public function __construct(
        private readonly PushManager $manager,
        private readonly SafeModeGuard $guard,
        private readonly TokenCipher $cipher,
        private readonly ClockInterface $clock,
    ) {}

    /** Cooling-down devices listed one by one in a run (the rest are only counted). */
    public const MAX_COOLING_LINES = 100;

    /**
     * The quarantined APNs device tokens of a scope, oldest first. A device probed less than the cooldown ago
     * (`devices.probe.cooldown_hours`) never takes one of the `$limit` places: it is reported as cooling down, so a
     * cron that runs every hour moves on to the next devices instead of starving on the same ones.
     *
     * @return array{devices: list<PushDevice>, cooling: list<PushDevice>, cooling_total: int, more: int}
     */
    public function select(string $scope, ?string $deviceId, int $limit, bool $ignoreCooldown = false): array
    {
        $class = PushDevice::modelClass();
        $query = (new $class)->newQuery()
            ->forScope($scope)
            ->where('provider', Provider::Apns->value)
            ->where('kind', DestinationKind::ApnsDeviceToken->value)
            ->where('state', DeviceState::Quarantined->value);
        if ($deviceId !== null) {
            $query->whereKey($deviceId);
        }

        $total = (clone $query)->count();
        $devices = $cooling = [];
        $examined = $coolingTotal = 0;
        foreach ($query->lazyById(100) as $device) {
            $examined++;
            if (! $ignoreCooldown && $this->coolingUntil($device) !== null) {
                $coolingTotal++;
                if (count($cooling) < self::MAX_COOLING_LINES) {
                    $cooling[] = $device;
                }

                continue;
            }
            $devices[] = $device;
            if (count($devices) >= $limit) {
                break;
            }
        }

        return ['devices' => $devices, 'cooling' => $cooling, 'cooling_total' => $coolingTotal, 'more' => max(0, $total - $examined)];
    }

    /** The configured cooldown in hours (0 = off). */
    public function cooldownHours(): int
    {
        $hours = config('mobile-push.devices.probe.cooldown_hours', 24);

        return is_numeric($hours) ? max(0, (int) $hours) : 24;
    }

    /** When the device may be probed again, or null when it may be probed now. */
    public function coolingUntil(PushDevice $device): ?\DateTimeImmutable
    {
        $hours = $this->cooldownHours();
        $last = is_array($device->metadata) && is_array($device->metadata['environment_probe'] ?? null) ? ($device->metadata['environment_probe']['at'] ?? null) : null;
        if ($hours <= 0 || ! is_string($last)) {
            return null;
        }
        try {
            $until = (new \DateTimeImmutable($last))->modify('+'.$hours.' hours');
        } catch (\Exception) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($this->clock->now()) < $until ? $until : null;
    }

    /**
     * One "cooling down" line per device that was probed too recently.
     *
     * @param  list<PushDevice>  $devices
     * @return list<ProbeRow>
     */
    public function coolingRows(array $devices): array
    {
        $rows = [];
        foreach ($devices as $device) {
            $until = $this->coolingUntil($device);
            $probe = is_array($device->metadata) && is_array($device->metadata['environment_probe'] ?? null) ? $device->metadata['environment_probe'] : [];
            $from = $device->environment;
            $to = $from === Environment::Production ? Environment::Sandbox : Environment::Production;
            try {
                $masked = TokenMasker::mask($this->cipher->decrypt($device->token, $device->scope, $device->kind->value));
            } catch (PrivacyException|\InvalidArgumentException|PushException) {
                $masked = '[unreadable]';
            }
            $rows[] = new ProbeRow(PushDevice::keyOf($device), $masked, $device->app_id, $from->value, $to->value, ProbeOutcome::CoolingDown, sprintf(
                'Probed at %s (%s): not contacted again before %s. Each rejected probe counts against Apple\'s error budget; --ignore-cooldown overrides this after a confirmation.',
                is_string($probe['at'] ?? null) ? $probe['at'] : 'an earlier run',
                is_string($probe['outcome'] ?? null) ? $probe['outcome'] : 'outcome not recorded',
                $until === null ? 'now' : $until->format('Y-m-d H:i:s \\U\\T\\C'),
            ));
        }

        return $rows;
    }

    /**
     * @param  list<PushDevice>  $devices
     * @return list<ProbeItem>
     */
    public function plan(string $scope, array $devices, ?string $connection): array
    {
        $items = [];
        foreach ($devices as $device) {
            $items[] = $this->item($scope, $device, $connection);
        }

        return $items;
    }

    /**
     * Probe the actionable items one by one and return one row per item (in order).
     *
     * @param  list<ProbeItem>  $items
     * @return array{rows: list<ProbeRow>, stopped: bool}
     */
    public function run(array $items): array
    {
        $rows = [];
        $stopped = false;

        foreach ($items as $item) {
            if (! $item->send) {
                $rows[] = $item->row;

                continue;
            }
            if ($stopped) {
                $rows[] = $item->row->with(ProbeOutcome::NotContacted, 'The run was stopped by an authentication error before this device.');

                continue;
            }

            $row = $this->probe($item);
            $this->record($item, $row);
            $stopped = $row->outcome === ProbeOutcome::AuthenticationError;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'stopped' => $stopped];
    }

    /**
     * Remember in the device row (`metadata.environment_probe`, a JSON column that survives restarts: no new column,
     * no cache to lose) when it was contacted and what came back. Only a probe that sent a request counts.
     */
    private function record(ProbeItem $item, ProbeRow $row): void
    {
        $contacted = [ProbeOutcome::Relabelled, ProbeOutcome::StillRejected, ProbeOutcome::Untouched, ProbeOutcome::Conflict, ProbeOutcome::Changed, ProbeOutcome::AuthenticationError];
        if (! in_array($row->outcome, $contacted, true)) {
            return;
        }

        $model = $item->device;
        $fresh = $model->newQuery()->whereKey($model->getKey())->first();
        if ($fresh === null) {
            return;
        }
        $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
        $metadata['environment_probe'] = [
            'at' => \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $row->outcome->value,
            'connection' => $item->target?->name,
        ];
        $fresh->metadata = $metadata;
        $fresh->save();
    }

    private function item(string $scope, PushDevice $device, ?string $connection): ProbeItem
    {
        $from = $device->environment;
        $to = $from === Environment::Production ? Environment::Sandbox : Environment::Production;
        $row = new ProbeRow(PushDevice::keyOf($device), '[unreadable]', $device->app_id, $from->value, $to->value, ProbeOutcome::Unreadable);

        try {
            $destination = PushDevice::build($device->kind, $this->cipher->decrypt($device->token, $device->scope, $device->kind->value));
        } catch (PrivacyException|\InvalidArgumentException|PushException) {
            return new ProbeItem($device, $row->with(ProbeOutcome::Unreadable, 'The stored token cannot be decrypted (key rotation?).'));
        }
        $row = new ProbeRow($row->deviceId, TokenMasker::mask($destination->value()), $device->app_id, $from->value, $to->value, ProbeOutcome::NotContacted);

        [$target, $why] = $this->target($scope, $device, $to, $connection);
        if ($target === null) {
            return new ProbeItem($device, $row->with(ProbeOutcome::NoConnection, $why ?? 'No connection.'));
        }

        $restriction = $this->restriction($target);
        if ($restriction !== null) {
            return new ProbeItem($device, $row->with(ProbeOutcome::KeyRestricted, $restriction));
        }

        $options = $this->manager->defaultOptions($target);
        $decision = $this->guard->decide($options->effectiveMode, $options->applicationEnvironment ?? '', $target, $destination, OperationKind::Probe, $options->live, $target->environment);
        if (! $decision->allowed) {
            return new ProbeItem($device, $row->with($decision->isSimulated() ? ProbeOutcome::NotSent : ProbeOutcome::Blocked, ($decision->isSimulated() ? 'Simulation mode: would probe through connection "' : 'Blocked by safe mode (connection "').$target->name.'"): '.($decision->hint ?? '')), false, $target, $destination);
        }

        return new ProbeItem($device, $row, true, $target, $destination);
    }

    /** The values of `connections.<name>.key_environments`. */
    public const KEY_ENVIRONMENTS = ['both', 'sandbox', 'production'];

    /**
     * What the operator declared about the key of the connection the probe would use: `both` (the default: nothing
     * is known, the probe tries and reacts to an authentication refusal), `sandbox` or `production` (Apple lets a
     * `.p8` key be restricted to one environment). Read from `mobile-push.connections.<name>.key_environments`, which
     * only exists for connections defined in the config file (a connection supplied by a resolver is `both`).
     *
     * @throws UsageError when the value is not one of the allowed ones (an invalid declaration is refused, never guessed)
     */
    private function declaredKeyEnvironments(BindingRef $target): string
    {
        $connections = config('mobile-push.connections', []);
        $connection = is_array($connections) ? ($connections[$target->name] ?? null) : null;
        $value = is_array($connection) ? ($connection['key_environments'] ?? 'both') : 'both';
        if (! is_string($value) || ! in_array($value, self::KEY_ENVIRONMENTS, true)) {
            throw new UsageError(sprintf(
                'mobile-push.connections.%s.key_environments must be one of: %s (got %s). Nothing was sent. Set it to "both" when the key is not restricted, or to the single environment Apple limited it to.',
                $target->name, implode(', ', self::KEY_ENVIRONMENTS), is_string($value) ? '"'.$value.'"' : get_debug_type($value),
            ));
        }

        return $value;
    }

    /** The refusal text when the declared key environments exclude the environment of the probe, or null. */
    private function restriction(BindingRef $target): ?string
    {
        $declared = $this->declaredKeyEnvironments($target);
        if ($declared === 'both' || $declared === $target->environment->value) {
            return null;
        }

        return sprintf(
            'Refused: connection "%s" declares key_environments=%s, so its key cannot be used on %s, the environment this probe would try. Nothing was sent. Remedy: probe through a connection whose key is valid on %s (an unrestricted key, or one issued for it), or, if the key is in fact not restricted, set key_environments to "both".',
            $target->name, $declared, $target->environment->value, $target->environment->value,
        );
    }

    private function probe(ProbeItem $item): ProbeRow
    {
        $target = $item->target;
        $destination = $item->destination;
        if ($target === null || $destination === null) {
            return $item->row->with(ProbeOutcome::Untouched, 'No target.');
        }

        $options = $this->manager->defaultOptions($target)
            ->withExpiresAt(\DateTimeImmutable::createFromInterface($this->clock->now())->modify('+5 minutes'));
        // A destination without a registry reference: the pipeline cannot apply the answer to the registry, only this
        // command does, and only after reading it.
        $results = iterator_to_array($this->manager->send($target, ApnsMessage::background(), [$destination], $options)->results(), false);
        $result = $results[0] ?? null;

        if (! $result instanceof SendResult) {
            return $item->row->with(ProbeOutcome::Untouched, 'The provider gave no answer for this device.');
        }

        $code = $result->reason->code;
        if ($result->status === SendStatus::Accepted) {
            return $this->relabel($item, $target);
        }
        if ($result->status === SendStatus::Simulated || $code === ReasonCode::PolicySafeMode || $code === ReasonCode::Simulated) {
            return $item->row->with(ProbeOutcome::NotSent, 'No request was sent ('.$code->value.').');
        }
        if (in_array($code, self::STOPS, true)) {
            return $item->row->with(ProbeOutcome::AuthenticationError, sprintf(
                'The provider refused the credential of connection "%s" (%s%s). A key limited to one environment answers like this. The run was stopped: no other device was contacted.',
                $target->name, $code->value, $result->reason->rawReason === null ? '' : ': '.$result->reason->rawReason,
            ));
        }
        if ($code === ReasonCode::DestinationRejected) {
            return $item->row->with(ProbeOutcome::StillRejected, 'Rejected on '.$target->environment->value.' as well: left quarantined.');
        }

        return $item->row->with(ProbeOutcome::Untouched, sprintf('Answer %s%s: the device was left untouched.', $result->status->value, $code === ReasonCode::None ? '' : '/'.$code->value));
    }

    /** Move the registration to the other environment and reactivate it with a new generation (compare-and-swap). */
    private function relabel(ProbeItem $item, BindingRef $target): ProbeRow
    {
        $model = $item->device;

        return $model->getConnection()->transaction(function () use ($item, $target, $model): ProbeRow {
            $device = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();

            if ($device === null || $device->state !== DeviceState::Quarantined || $device->generation !== $model->generation) {
                return $item->row->with(ProbeOutcome::Changed, 'Accepted, but the registration changed meanwhile: nothing was updated.');
            }

            $clash = $model->newQuery()
                ->where('binding_identity', $target->identity())
                ->where('kind', $device->kind->value)
                ->where('token_index', $device->token_index)
                ->whereKeyNot($device->getKey())
                ->exists();
            if ($clash) {
                return $item->row->with(ProbeOutcome::Conflict, 'Accepted, but this token is already registered for the other environment: nothing was updated.');
            }

            $now = CarbonImmutable::instance($this->clock->now())->setTimezone(date_default_timezone_get());
            $device->binding_name = $target->name;
            $device->binding_identity = $target->identity();
            $device->environment = $target->environment;
            $device->state = DeviceState::Active;
            $device->state_reason = null;
            $device->state_changed_at = $now;
            $device->registered_at = $now;
            $device->generation = $device->generation + 1;
            $device->save();

            return $item->row->with(ProbeOutcome::Relabelled, sprintf('Accepted on %s: re-labelled and active, generation %d.', $target->environment->value, $device->generation));
        });
    }

    /** @return array{0: ?BindingRef, 1: ?string} the binding of the same app in the other environment, or why none */
    private function target(string $scope, PushDevice $device, Environment $to, ?string $connection): array
    {
        $matches = [];
        foreach ($this->bindings($scope, $connection) as $binding) {
            if ($binding->appId === $device->app_id && $binding->environment === $to && $binding->scope === $device->scope) {
                $matches[] = $binding;
            }
        }

        if (count($matches) === 1) {
            return [$matches[0], null];
        }
        if ($matches === []) {
            return [null, sprintf('No APNs connection for the app "%s" on the %s environment in scope "%s"%s. The probe needs one (same scope, same bundle id).', $device->app_id, $to->value, $scope, $connection === null ? '' : ' named "'.$connection.'"')];
        }

        return [null, sprintf('Several APNs connections match the app "%s" on %s (%s): name one with --connection.', $device->app_id, $to->value, implode(', ', array_map(static fn (BindingRef $b): string => $b->name, $matches)))];
    }

    /** @return list<BindingRef> */
    private function bindings(string $scope, ?string $connection): array
    {
        $key = $scope.'|'.($connection ?? '*');
        if (isset($this->apnsBindings[$key])) {
            return $this->apnsBindings[$key];
        }

        $found = [];
        foreach ($connection === null ? $this->manager->connectionNames($scope) : [$connection] as $name) {
            try {
                if ($this->manager->providerOf($scope, $name) !== Provider::Apns) {
                    continue;
                }
                $found[] = $this->manager->binding($name, $scope);
            } catch (PushException) {
                continue; // unknown or unusable connection: it cannot be the target
            }
        }

        return $this->apnsBindings[$key] = $found;
    }
}
