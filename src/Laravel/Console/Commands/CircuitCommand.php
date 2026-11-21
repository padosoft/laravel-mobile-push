<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\Millis;
use Padosoft\MobilePush\Core\Guard\StateCell;
use Padosoft\MobilePush\Laravel\Console\Support\ConnectionInspector;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;
use Psr\Clock\ClockInterface;

/**
 * Shows the authentication breaker, the destination-quality breaker and the provider throttle of a connection;
 * `--reset` closes the two breakers after confirmation (a provider throttle is the provider's request and is left alone).
 *
 * Exit codes: 0 everything closed; 1 a breaker is open/half-open or the provider is throttling; 2 invalid input
 * (unknown connection, or a reset that was not confirmed).
 */
final class CircuitCommand extends PushCommand
{
    private const STATES = [0 => 'closed', 1 => 'open', 2 => 'half-open'];

    protected $signature = 'mobile-push:circuit
        {connection? : Connection name (default: every configured connection)}
        {--reset : Close the breakers after confirmation}
        {--force : With --reset: do not ask for confirmation}
        {--json : Print the result as JSON}';

    protected $description = 'Show (and optionally reset) the circuit breakers and provider throttle of a connection';

    public function handle(PushManager $manager, ConnectionInspector $inspector, GuardStore $store, ClockInterface $clock, SendingSettings $settings): int
    {
        $name = $this->argument('connection');
        $names = is_string($name) && $name !== '' ? [$name] : $inspector->names();
        if ($names === []) {
            return $this->failWith('No connection is configured.', 2, 'Add one under mobile-push.connections.');
        }

        try {
            $keys = [];
            foreach ($names as $n) {
                $driver = $manager->driver($manager->binding($n));
                $keys[$n] = [
                    'auth' => 'mp:auth:'.$driver->credentialIdentity(),
                    'dest' => 'mp:dest:'.$driver->binding()->identity(),
                    'gate' => 'mp:gate:'.$driver->accountKey(),
                ];
            }
        } catch (PushException $e) {
            return $this->failWith($e->getMessage(), 2, 'Name a configured connection with a valid configuration (see mobile-push:connections).');
        }

        if ((bool) $this->option('reset')) {
            $closed = $this->reset($names, $keys, $store, $clock);
            if ($closed === null) {
                return 2;
            }
        }

        $now = Millis::of($clock->now());
        $states = [];
        $attention = false;
        foreach ($keys as $n => $k) {
            $auth = StateCell::read($store, $k['auth']);
            $dest = StateCell::read($store, $k['dest']);
            $gate = StateCell::read($store, $k['gate']);
            $authState = self::STATES[$auth['s'] ?? 0] ?? 'closed';
            $destState = self::STATES[$dest['s'] ?? 0] ?? 'closed';
            $until = ($gate['until'] ?? 0) > $now ? $gate['until'] : null;
            $attention = $attention || $authState !== 'closed' || $destState !== 'closed' || $until !== null;
            $states[$n] = [
                'auth' => [
                    'state' => $authState,
                    'consecutive_failures' => $auth['f'] ?? 0,
                    'credential_generation' => $auth['gen'] ?? 0,
                    'threshold' => $settings->authThreshold,
                    'retry_at' => isset($auth['at']) && $authState !== 'closed' ? Millis::toDate($auth['at'] + $settings->authCooldown * 1000)->format(DATE_ATOM) : null,
                ],
                'destination_quality' => [
                    'state' => $destState,
                    'sample' => $dest['n'] ?? 0,
                    'bad' => $dest['b'] ?? 0,
                    'retry_at' => isset($dest['at']) && $destState !== 'closed' ? Millis::toDate($dest['at'] + $settings->qualityCooldown * 1000)->format(DATE_ATOM) : null,
                ],
                'provider_throttle' => ['paused_until' => $until === null ? null : Millis::toDate($until)->format(DATE_ATOM)],
            ];
        }
        $exit = $attention ? 1 : 0;

        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => $exit, 'connections' => $states]);

            return $exit;
        }

        foreach ($states as $n => $s) {
            $this->newLine();
            $this->line('<options=bold>Connection "'.$n.'"</>');
            $a = $s['auth'];
            $this->line(sprintf('  Authentication breaker: %s%s (failures in a row: %d of %d, credential generation %d)', $a['state'], $a['retry_at'] !== null ? ', next probe after '.$a['retry_at'] : '', $a['consecutive_failures'], $a['threshold'], $a['credential_generation']));
            $q = $s['destination_quality'];
            $this->line(sprintf('  Destination-quality breaker: %s%s (sample %d, bad %d)', $q['state'], $q['retry_at'] !== null ? ', next probe after '.$q['retry_at'] : '', $q['sample'], $q['bad']));
            $t = $s['provider_throttle']['paused_until'];
            $this->line('  Provider throttle: '.($t === null ? 'none' : 'sending is paused until '.$t));
        }
        $this->newLine();
        $this->line($exit === 0 ? 'Everything is closed (exit 0).' : 'Something is open or throttled (exit 1). Fix the cause first: resetting a breaker that opened for a real reason only reopens it.');

        return $exit;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array{auth: string, dest: string, gate: string}>  $keys
     * @return int|null number of breakers closed; null when not confirmed (a failure was already printed)
     */
    private function reset(array $names, array $keys, GuardStore $store, ClockInterface $clock): ?int
    {
        if (! (bool) $this->option('force')) {
            if (! $this->canAsk()) {
                $this->failWith('--reset needs a confirmation and this terminal is not interactive.', 2, 'Run it interactively, or add --force.');

                return null;
            }
            $question = sprintf('Close the authentication and destination-quality breakers of %s? Sends resume at once.', implode(', ', $names));
            if (! $this->confirm($question, false)) {
                $this->failWith('Reset not confirmed: nothing was changed.', 2);

                return null;
            }
        }

        $now = Millis::of($clock->now());
        $closed = 0;
        foreach ($keys as $k) {
            $closed += StateCell::update($store, $k['auth'], 86400, static function (array $st): array {
                if (($st['s'] ?? 0) === 0) {
                    return [$st, 0];
                }

                return [['gen' => $st['gen'] ?? 0, 's' => 0, 'f' => 0], 1];
            });
            $closed += StateCell::update($store, $k['dest'], 86400, static function (array $st) use ($now): array {
                if (($st['s'] ?? 0) === 0) {
                    return [$st, 0];
                }

                return [['s' => 0, 'since' => $now, 'n' => 0, 'b' => 0], 1];
            });
        }
        if (! $this->wantsJson()) {
            $this->components->info(sprintf('%d breaker(s) closed.', $closed));
        }

        return $closed;
    }
}
