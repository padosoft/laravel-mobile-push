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
 *  - a refusal of the credential (a key limited to one environment answers this way): the WHOLE run stops;
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

    /**
     * The quarantined APNs device tokens of a scope, oldest first.
     *
     * @return array{devices: list<PushDevice>, more: int}
     */
    public function select(string $scope, ?string $deviceId, int $limit): array
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
        /** @var list<PushDevice> $devices */
        $devices = array_values($query->orderBy((new $class)->getKeyName())->limit($limit)->get()->all());

        return ['devices' => $devices, 'more' => max(0, $total - count($devices))];
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
            $stopped = $row->outcome === ProbeOutcome::AuthenticationError;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'stopped' => $stopped];
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

        $options = $this->manager->defaultOptions($target);
        $decision = $this->guard->decide($options->effectiveMode, $options->applicationEnvironment ?? '', $target, $destination, OperationKind::Probe, $options->live, $target->environment);
        if (! $decision->allowed) {
            return new ProbeItem($device, $row->with($decision->isSimulated() ? ProbeOutcome::NotSent : ProbeOutcome::Blocked, ($decision->isSimulated() ? 'Simulation mode: would probe through connection "' : 'Blocked by safe mode (connection "').$target->name.'"): '.($decision->hint ?? '')), false, $target, $destination);
        }

        return new ProbeItem($device, $row, true, $target, $destination);
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
