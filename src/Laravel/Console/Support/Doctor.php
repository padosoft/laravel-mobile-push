<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Transport\Curl\CurlCapabilities;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule;
use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Integration\ModuleMigrations;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;
use Padosoft\MobilePush\Laravel\Queue\ChainStatus;
use Padosoft\MobilePush\Laravel\Queue\QueueModule;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;

/**
 * The checks behind `mobile-push:doctor`. Offline checks never touch a provider; the live probes (a real request each)
 * run only through live(). A check that cannot be made says "not verifiable": it is never reported as ok.
 */
final class Doctor
{
    public const REQUIRED_EXTENSIONS = ['curl', 'openssl', 'json'];

    public function __construct(
        private readonly Application $app,
        private readonly PushManager $manager,
        private readonly ConnectionInspector $inspector,
        private readonly Redactor $redactor = new Redactor,
    ) {}

    public function mode(): ExecutionMode
    {
        return (new ModeResolver)->resolve($this->manager->settings()->mode, (string) $this->app->environment());
    }

    /**
     * @return list<DoctorCheck>
     */
    public function run(?string $only = null): array
    {
        $checks = [...$this->php(), ...$this->runtime(), ...$this->configuration()];

        try {
            $mode = $this->mode();
            $checks = [...$checks, ...$this->executionMode($mode)];
        } catch (PushException $e) {
            $checks[] = DoctorCheck::failed('Execution mode', $this->redactor->string($e->getMessage()), 'Set MOBILE_PUSH_MODE to auto, live or simulate.');
        }

        $checks = [...$checks, ...$this->connections($only)];

        if ($only === null) {
            $checks = [...$checks, ...$this->cache(), ...$this->queue(), ...$this->database()];
        }
        $checks[] = new DoctorCheck('Clock', DoctorStatus::Info, 'Clock skew cannot be checked offline: keep NTP/chrony running on every server. APNs refuses provider tokens signed more than about an hour away from its own clock (ExpiredProviderToken).');

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ PHP and curl

    /** @return list<DoctorCheck> */
    private function php(): array
    {
        $s = 'PHP and curl';
        $checks = [];
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $e): bool => ! extension_loaded($e)));
        $checks[] = $missing === []
            ? DoctorCheck::ok($s, 'PHP '.PHP_VERSION.' with the required extensions ('.implode(', ', self::REQUIRED_EXTENSIONS).').')
            : DoctorCheck::failed($s, 'Missing PHP extension(s): '.implode(', ', $missing).'.', 'Install and enable them (for example apt install php-curl), then restart PHP-FPM and the queue workers.');

        $caps = CurlCapabilities::detect();
        if (! $caps->extensionLoaded) {
            return [...$checks, DoctorCheck::failed($s, 'The curl extension is not loaded, so nothing can be sent.', 'Install ext-curl and restart PHP.')];
        }
        $checks[] = DoctorCheck::info($s, sprintf('libcurl %s, %s.', $caps->libcurlVersion, $caps->sslVersion));
        $checks[] = $caps->libcurlVersionNumber >= CurlCapabilities::MIN_LIBCURL && $caps->missingConstants === []
            ? DoctorCheck::ok($s, 'libcurl has every constant the transport needs.')
            : DoctorCheck::failed($s, 'libcurl is too old for the push transport'.($caps->missingConstants !== [] ? ' (missing '.implode(', ', $caps->missingConstants).')' : '').'.', 'Use PHP built against libcurl 7.68 or newer (8.x recommended); the official PHP Docker images are fine.');
        $checks[] = $caps->http2
            ? DoctorCheck::ok($s, 'HTTP/2 is available (APNs requires it).')
            : DoctorCheck::failed($s, 'libcurl has no HTTP/2 support, which APNs requires.', 'Install a libcurl built with nghttp2 (curl -V must list HTTP2).');
        $checks[] = $caps->alpn()
            ? DoctorCheck::ok($s, 'TLS with ALPN is available.')
            : DoctorCheck::failed($s, 'HTTP/2 over TLS needs ALPN, which '.$caps->sslVersion.' does not provide.', 'Link libcurl against OpenSSL 1.0.2 or newer (3.x recommended).');
        $checks[] = $caps->blobOptions
            ? DoctorCheck::ok($s, 'Client certificates can be passed in memory (certificate-based APNs works).')
            : DoctorCheck::warning($s, 'libcurl cannot take client certificates in memory (needs 7.71 or newer): certificate-based APNs will not work.', 'Use a .p8 key (token authentication), or upgrade libcurl.');

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ runtime

    /**
     * Octane on Swoole with the curl coroutine hook: read from the application's Octane config, because the doctor
     * runs in a console process where the hook is never on. With the hook, a second request can enter the transport's
     * connection pool while the first one waits inside curl; measured with eight requests at a time, most of them
     * failed and the workers were restarted (docs/runtimes.md).
     *
     * @return list<DoctorCheck>
     */
    private function runtime(): array
    {
        $flags = config('octane.swoole.options.hook_flags');
        // SWOOLE_HOOK_CURL and SWOOLE_HOOK_NATIVE_CURL, by value: the extension is not loaded in every console.
        if (! is_int($flags) || ($flags & (2048 | 4096)) === 0) {
            return [];
        }

        return [DoctorCheck::warning(
            'Runtime',
            'Octane\'s Swoole options turn on the curl coroutine hook (octane.swoole.options.hook_flags): pushes sent from HTTP workers are not safe under concurrent requests.',
            'Send from queue workers, or take SWOOLE_HOOK_CURL and SWOOLE_HOOK_NATIVE_CURL out of hook_flags (for example SWOOLE_HOOK_ALL & ~(SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL)).',
        )];
    }

    // ------------------------------------------------------------------------------------------ configuration

    /** @return list<DoctorCheck> */
    private function configuration(): array
    {
        $s = 'Configuration';
        try {
            $settings = $this->app->make(SendingSettings::class);
            $checks = [DoctorCheck::ok($s, 'config/mobile-push.php parses (delivery profile: '.$settings->deliveryProfile.').')];
        } catch (PushException $e) {
            return [DoctorCheck::failed($s, $this->redactor->string($e->getMessage()), 'Fix config/mobile-push.php (or the matching MOBILE_PUSH_* variables) and run php artisan config:clear.')];
        }

        if ($this->inspector->names() === []) {
            $checks[] = DoctorCheck::warning($s, 'No connection is configured.', 'Add one under mobile-push.connections (run mobile-push:install for the variables to set).');
        }

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ execution mode

    /** @return list<DoctorCheck> */
    private function executionMode(ExecutionMode $mode): array
    {
        $s = 'Execution mode';
        $env = (string) $this->app->environment();
        $configured = $this->manager->settings()->mode;

        if ($mode === ExecutionMode::Simulate) {
            return [new DoctorCheck($s, DoctorStatus::Info, sprintf('SIMULATED: mode is "%s" and APP_ENV is "%s", so NO request is sent to APNs or FCM; every send ends as "simulated".', $configured, $env),
                'To send for real set MOBILE_PUSH_MODE=live (outside production also set live.allow=true on the connection and list your test devices in live.only_to).')];
        }
        if ($env !== ModeResolver::PRODUCTION) {
            return [DoctorCheck::warning($s, sprintf('LIVE sending is enabled outside production (mode "%s", APP_ENV "%s"): real requests can leave this machine.', $configured, $env), 'Only test devices listed in live.only_to receive pushes here. Set MOBILE_PUSH_MODE=auto to go back to simulation.')];
        }

        return [DoctorCheck::ok($s, sprintf('Live sending (mode "%s", APP_ENV "production").', $configured))];
    }

    // ------------------------------------------------------------------------------------------ connections

    /** @return list<DoctorCheck> */
    private function connections(?string $only): array
    {
        $names = $this->inspector->names();
        if ($only !== null) {
            if (! in_array($only, $names, true)) {
                return [DoctorCheck::failed('Connections', sprintf('There is no connection named "%s" in mobile-push.connections.', $only), 'Known connections: '.($names === [] ? 'none' : implode(', ', $names)).'.')];
            }
            $names = [$only];
        }

        $checks = [];
        foreach ($names as $name) {
            $info = $this->inspector->inspect($name);
            $s = 'Connection "'.$name.'"';
            if ($info->error !== null) {
                [$cause, $remedy] = self::split($info->error);
                $checks[] = DoctorCheck::failed($s, $cause, $remedy);

                continue;
            }
            foreach ($info->findings as $finding) {
                [$cause, $remedy] = self::split($this->redactor->string($finding->message));
                $checks[] = match ($finding->severity) {
                    Severity::Ok => DoctorCheck::ok($s, $cause),
                    Severity::Warning => DoctorCheck::warning($s, $cause, $remedy),
                    Severity::Error => DoctorCheck::failed($s, $cause, $remedy),
                };
            }
            foreach ($info->notes as $note) {
                $checks[] = DoctorCheck::notVerifiable($s, $note);
            }
            if (! $info->hasErrors()) {
                $checks[] = DoctorCheck::ok($s, sprintf('%s: %s (%s, %s), generation %s.', $info->provider->value ?? '?', $info->appId ?? '?', $info->environment ?? '?', $info->identity ?? $info->auth ?? '?', $info->generation ?? '?'));
            }
        }

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ cache

    /** @return list<DoctorCheck> */
    private function cache(): array
    {
        $s = 'Cache';
        $name = config('mobile-push.cache.store');
        $name = is_string($name) && $name !== '' ? $name : null;
        $prefix = Cfg::string('mobile-push.cache.prefix', 'mobile-push');

        try {
            $repository = $this->app->make('cache')->store($name);
            $key = $prefix.':doctor:probe';
            $value = bin2hex(random_bytes(6));
            $repository->put($key, $value, 30);
            $read = $repository->get($key);
            $repository->forget($key);
        } catch (\Throwable $e) {
            return [DoctorCheck::failed($s, sprintf('The cache store %s is not reachable (%s).', $name ?? '(default)', $e::class), 'Check the cache server and mobile-push.cache.store (MOBILE_PUSH_CACHE_STORE). Without it provider tokens, breakers and rate limits cannot be shared between workers.')];
        }
        if ($read !== $value) {
            return [DoctorCheck::failed($s, 'The cache store did not return what was written (is it the "null" store?).', 'Point mobile-push.cache.store at a real store such as redis.')];
        }

        $checks = [DoctorCheck::ok($s, sprintf('The cache store %s is reachable.', $name ?? '(default)'))];
        $store = $repository->getStore();
        if (! $store instanceof LockProvider) {
            $checks[] = DoctorCheck::warning($s, 'The cache store cannot take locks: token refresh and breaker updates are not atomic across workers.', 'Use redis, memcached, database or dynamodb.');

            return $checks;
        }

        try {
            $lock = $store->lock($prefix.':doctor:lock', 5);
            if ($lock->get()) {
                $lock->release();
                $checks[] = DoctorCheck::ok($s, 'The cache store takes locks.');
            } else {
                $checks[] = DoctorCheck::warning($s, 'The lock probe is held by another process right now.', 'Run the doctor again.');
            }
        } catch (\Throwable $e) {
            $checks[] = DoctorCheck::failed($s, sprintf('Reading and writing work but locking failed (%s).', $e::class), 'Check the cache server permissions (SET NX / Lua support).');
        }

        $driver = $name === null ? config('cache.default') : $name;
        $config = config('cache.stores.'.(is_string($driver) ? $driver : ''));
        $kind = is_array($config) && is_string($config['driver'] ?? null) ? $config['driver'] : null;
        if (in_array($kind, ['array', 'file'], true) && $this->app->environment() === ModeResolver::PRODUCTION) {
            $checks[] = DoctorCheck::warning($s, sprintf('The "%s" cache driver is shared by one process (array) or one host (file) only.', $kind), 'Use redis, memcached, database or dynamodb in production so every worker sees the same tokens and breakers.');
        }

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ queue

    /** @return list<DoctorCheck> */
    private function queue(): array
    {
        $s = 'Queue timeouts';

        try {
            $chain = QueueModule::timeouts($this->app);
        } catch (PushException $e) {
            return [DoctorCheck::failed($s, $this->redactor->string($e->getMessage()), 'Fix mobile-push.queue.* and run php artisan config:clear.')];
        }

        $checks = [];
        foreach ($chain->links as $link) {
            $label = sprintf('%s (%s) < %s (%s), margin %s', $link->lower, self::seconds($link->lowerSeconds), $link->upper, self::seconds($link->upperSeconds), self::seconds($link->marginSeconds));
            $checks[] = match ($link->status) {
                ChainStatus::Ok => DoctorCheck::ok($s, $label),
                ChainStatus::Violation => DoctorCheck::failed($s, $label.': violation', $link->remedy),
                ChainStatus::NotVerifiable => DoctorCheck::notVerifiable($s, $label.': not verifiable', $link->remedy),
                ChainStatus::NotApplicable => DoctorCheck::info($s, $label.': not applicable'.($link->remedy !== null ? ' ('.$link->remedy.')' : '')),
            };
        }

        return $checks;
    }

    private static function seconds(?float $value): string
    {
        return $value === null ? '?' : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.').' s';
    }

    // ------------------------------------------------------------------------------------------ database

    /** @return list<DoctorCheck> */
    private function database(): array
    {
        $s = 'Database';
        $needed = [];
        if ((bool) config('mobile-push.devices.enabled', false)) {
            $needed[] = ['devices', config('mobile-push.devices.connection'), [Cfg::string('mobile-push.devices.table', 'mobile_push_devices')], DevicesModule::PUBLISH_TAG];
        }
        if (config('mobile-push.delivery.profile', 'light') === 'durable') {
            $needed[] = ['durable ledger', config('mobile-push.ledger.connection'), [
                Cfg::string('mobile-push.ledger.tables.effects', 'mobile_push_effects'),
                Cfg::string('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries'),
            ], LedgerModule::PUBLISH_TAG];
        }
        $sinks = config('mobile-push.log.sinks', []);
        if (is_array($sinks) && in_array('database', $sinks, true)) {
            $needed[] = ['database log sink', config('mobile-push.log.database.connection'), [Cfg::string('mobile-push.log.database.table', 'mobile_push_logs')], LoggingModule::PUBLISH_TAG];
        }
        if ((bool) config('mobile-push.live_activities.enabled', false)) {
            $needed[] = ['live activities', config('mobile-push.live_activities.connection'), [
                Cfg::string('mobile-push.live_activities.table', 'mobile_push_live_activities'),
                Cfg::string('mobile-push.live_activities.push_to_start_table', 'mobile_push_live_activity_push_to_start_tokens'),
                Cfg::string('mobile-push.live_activities.channels_table', 'mobile_push_broadcast_channels'),
            ], LiveActivitiesModule::PUBLISH_TAG];
        }
        if ((bool) config('mobile-push.campaigns.enabled', false)) {
            $needed[] = ['campaigns', config('mobile-push.campaigns.connection'), [Cfg::string('mobile-push.campaigns.table', 'mobile_push_campaigns')], CampaignsModule::PUBLISH_TAG];
        }

        $checks = [];
        if ((bool) config('mobile-push.campaigns.enabled', false) && config('mobile-push.delivery.profile', 'light') !== 'durable') {
            $checks[] = DoctorCheck::warning($s, 'Campaigns are enabled but the delivery profile is "light": campaigns are planned in the ledger and cannot be dispatched.', 'Set MOBILE_PUSH_DELIVERY_PROFILE=durable, or disable campaigns.');
        }
        if ((bool) config('mobile-push.receipts.enabled', false) && config('mobile-push.delivery.profile', 'light') !== 'durable') {
            $checks[] = DoctorCheck::warning($s, 'Receipts are enabled but the delivery profile is "light": receipts write to the ledger and stay inactive.', 'Set MOBILE_PUSH_DELIVERY_PROFILE=durable, or disable receipts.');
        }
        if ($needed === []) {
            return [...$checks, DoctorCheck::info($s, 'No enabled module needs a table.')];
        }

        foreach ($needed as [$module, $connection, $tables, $tag]) {
            try {
                $schema = $this->app->make('db')->connection(is_string($connection) && $connection !== '' ? $connection : null)->getSchemaBuilder();
                $missing = array_values(array_filter($tables, static fn (string $t): bool => ! $schema->hasTable($t)));
            } catch (\Throwable $e) {
                $checks[] = DoctorCheck::failed($s, sprintf('The database for the %s module is not reachable (%s).', $module, $e::class), 'Check the database connection configuration.');

                continue;
            }
            $checks[] = $missing === []
                ? DoctorCheck::ok($s, sprintf('The %s tables exist (%s).', $module, implode(', ', $tables)))
                : DoctorCheck::failed($s, sprintf('The %s module is enabled but table(s) %s are missing.', $module, implode(', ', $missing)), ModuleMigrations::autoload() ? 'Run php artisan migrate (mobile-push.migrations.autoload is on).' : 'Run php artisan mobile-push:install (or php artisan vendor:publish --tag='.$tag.') then php artisan migrate.');
        }

        return $checks;
    }

    // ------------------------------------------------------------------------------------------ live probes

    /**
     * Connections a live probe would use: configured, readable and without credential errors.
     *
     * @return list<ConnectionInfo>
     */
    public function liveTargets(?string $only): array
    {
        $names = $only === null ? $this->inspector->names() : [$only];
        $targets = [];
        foreach ($names as $name) {
            $info = $this->inspector->inspect($name);
            if (! $info->hasErrors() && $info->provider !== null) {
                $targets[] = $info;
            }
        }

        return $targets;
    }

    /**
     * One REAL request per connection: APNs gets a push to an obviously invalid device token (expected answer
     * BadDeviceToken: it proves the authentication works), FCM gets a validate_only call.
     *
     * @param  list<ConnectionInfo>  $targets
     * @return list<DoctorCheck>
     */
    public function live(array $targets): array
    {
        $checks = [];
        foreach ($targets as $info) {
            $s = 'Live probe "'.$info->name.'"';
            try {
                $binding = $this->manager->binding($info->name);
                $options = $this->manager->defaultOptions($binding);
                if ($info->provider === Provider::Apns) {
                    $destination = ApnsDeviceToken::of(str_repeat('0', 64));
                    $message = PushMessage::make('mobile-push doctor', 'connectivity probe');
                } else {
                    $destination = FcmToken::of('mobile-push-doctor-invalid-token');
                    $message = PushMessage::make('mobile-push doctor', 'connectivity probe');
                    $options = $options->withValidateOnly(true);
                }
                if ($options->live->allow) {
                    // Outside production the operator allowed live sends from this connection: the probe's own
                    // destination joins the allow-list for this one request. It cannot reach a device (an invalid
                    // APNs token; a validate_only call for FCM). Without live.allow the probe stays blocked.
                    $options = $options->withLive(new LiveSettings(
                        true,
                        [...$options->live->onlyTo, $this->app->make(TokenIndex::class)->index($destination, $binding->scope)],
                        $options->live->allowTopics,
                        $options->live->allowBroadcast,
                        $options->live->allowChannelManagement,
                    ));
                }
                $result = $this->firstResult($this->manager->send($binding, $message, [$destination], $options)->results());
            } catch (PushException $e) {
                $checks[] = DoctorCheck::failed($s, $this->redactor->string($e->getMessage()));

                continue;
            }
            $checks[] = $result instanceof SendResult
                ? self::verdict($s, $info->provider ?? Provider::Apns, $result)
                : DoctorCheck::failed($s, 'The probe produced no result.');
        }

        return $checks;
    }

    /** @param  iterable<SendResult>  $results */
    private function firstResult(iterable $results): ?SendResult
    {
        $first = null;
        foreach ($results as $result) {
            $first ??= $result;
        }

        return $first;
    }

    public static function verdict(string $section, Provider $provider, SendResult $result): DoctorCheck
    {
        $kind = $provider === Provider::Apns ? 'APNs request to an invalid device token' : 'FCM validate_only request';
        $code = $result->reason->code;
        $raw = $result->reason->rawReason !== null ? ' ('.$result->reason->rawReason.')' : '';
        $authFailure = in_array($code, [ReasonCode::AuthTokenExpired, ReasonCode::AuthTokenInvalid, ReasonCode::AuthTokenThrottled, ReasonCode::AuthCertificateInvalid, ReasonCode::AuthForbidden], true);

        return match (true) {
            $result->status === SendStatus::Accepted => DoctorCheck::ok($section, $kind.' was accepted: authentication works (the provider accepted a push to an invalid token, which is unusual but harmless).'),
            $result->status === SendStatus::Validated => DoctorCheck::ok($section, $kind.' was validated: authentication works.'),
            $authFailure => DoctorCheck::failed($section, $kind.' was refused for authentication reasons: '.$code->value.$raw.'.', $result->reason->hint),
            $result->status === SendStatus::Rejected && $result->httpStatus !== null && $result->httpStatus < 500 => DoctorCheck::ok($section, sprintf('%s answered %s%s with HTTP %d: the provider authenticated the request (this is the expected answer for an invalid token).', $provider === Provider::Apns ? 'APNs' : 'FCM', $code->value, $raw, $result->httpStatus)),
            $result->status === SendStatus::Skipped => DoctorCheck::warning($section, 'The probe was blocked before the network: '.($result->reason->hint ?? $code->value), 'Outside production a live probe needs live.allow = true on the connection (the probe cannot reach a device, so it does not need an entry in live.only_to); or run the doctor from production.'),
            $result->status === SendStatus::Simulated => DoctorCheck::warning($section, 'The probe was simulated: no request was sent.', 'Set MOBILE_PUSH_MODE=live.'),
            $result->status === SendStatus::OutcomeUnknown => DoctorCheck::failed($section, $kind.' ended with an unknown outcome: the connection failed after the request was written.', 'Check outbound HTTPS/HTTP2 access to the provider (firewall, proxy) and run the probe again.'),
            default => DoctorCheck::failed($section, sprintf('%s ended as %s (%s)%s.', $kind, $result->status->value, $code->value, $raw), $result->reason->hint),
        };
    }

    // ------------------------------------------------------------------------------------------ helpers

    /** @return array{0: string, 1: string|null} cause and remedy: the first sentence and the rest */
    private static function split(string $message): array
    {
        $parts = preg_split('/(?<=\.)\s+(?=[A-Z])/', $message, 2) ?: [$message];

        return [rtrim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
    }

    /** @param  list<DoctorCheck>  $checks */
    public static function exitCode(array $checks): int
    {
        $exit = 0;
        foreach ($checks as $check) {
            if ($check->status === DoctorStatus::Failed) {
                return 2;
            }
            if (in_array($check->status, [DoctorStatus::Warning, DoctorStatus::NotVerifiable], true)) {
                $exit = 1;
            }
        }

        return $exit;
    }
}
