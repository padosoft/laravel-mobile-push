<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Benchmarks;

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;
use Pushok\AuthProvider\Token;
use Pushok\Client;
use Pushok\Notification;
use Pushok\Payload;
use Pushok\Payload\Alert;

/**
 * Comparison with edamov/pushok. Facts only: what each library did with the same input against the same mock.
 *
 * How pushok is pointed at the mock without patching it (Client::__construct accepts curl options and they take
 * precedence over the ones pushok sets itself: `$this->curlOptions + $request->getOptions()`):
 *   CURLOPT_PORT            = mock port        (pushok sets 443)
 *   CURLOPT_RESOLVE         = api.push.apple.com:PORT:127.0.0.1
 *   CURLOPT_CAINFO          = the mock's CA    (peer verification stays ON)
 *   CURLOPT_SSL_VERIFYHOST  = 0                (the mock certificate is issued for localhost/127.0.0.1, not for api.push.apple.com)
 *   CURLOPT_PROXY           = ''               (the container sets HTTPS_PROXY; pushok does not disable it)
 * Library settings: setNbConcurrentRequests(window), setMaxConcurrentConnections(connections),
 * setAutoCloseConnections(false|true) per variant. Auth: Token::useExisting() with a made-up token (no key, no signing).
 */
final class Compare
{
    public static function run(array $p): array
    {
        self::assertPushok();
        $speed = self::speed($p);
        $behaviour = self::behaviour();
        $speed['sections'] = ['behaviour' => self::formatBehaviour($behaviour)];
        $speed['behaviour'] = $behaviour;
        $speed['pushok_options'] = self::pushokOptionsDescription();

        return $speed;
    }

    public static function behaviourOnly(array $p): array
    {
        self::assertPushok();
        $b = self::behaviour();

        return ['scenario' => 'behaviour-pushok', 'description' => 'Behaviour of this package and edamov/pushok on the same mock-scripted situations.', 'cases' => [], 'behaviour' => $b, 'sections' => ['behaviour' => self::formatBehaviour($b)], 'pushok_options' => self::pushokOptionsDescription()];
    }

    private static function assertPushok(): void
    {
        if (! class_exists(Client::class)) {
            throw new \RuntimeException('edamov/pushok is not installed (composer require --dev edamov/pushok).');
        }
    }

    // ------------------------------------------------------------------ speed

    private static function speed(array $p): array
    {
        $o = Scenarios::opts($p, ['count' => 5000, 'delay' => 10, 'window' => 100, 'repeat' => 5]);
        $variants = [];

        $server = MockServer::start();
        $variants['ours: raw CurlTransport (warm pool)'] = self::oursRaw($server, $o);
        $server = MockServer::start();
        $variants['ours: full Sender pipeline (warm pool)'] = self::oursPipeline($server, $o, true);
        $server = MockServer::start();
        $variants['ours: full Sender pipeline (new pool per run)'] = self::oursPipeline($server, $o, false);
        $server = MockServer::start();
        $variants['pushok (autoCloseConnections=false, warm)'] = self::pushok($server, $o, true);
        $server = MockServer::start();
        $variants['pushok (library default: connection closed after each push())'] = self::pushok($server, $o, false);

        $names = array_keys($variants);
        $runs = array_fill_keys($names, []);
        $discarded = [];
        $orders = [];
        for ($round = 0; $round <= $o['repeat']; $round++) {
            $order = $names;
            for ($k = 0; $k < $round % count($names); $k++) {
                $order[] = array_shift($order);
            }
            if ($round % 2 === 1) {
                $order = array_reverse($order);
            }
            $orders[] = $order;
            foreach ($order as $name) {
                $result = $variants[$name]['run']();
                if ($round === 0) {
                    $discarded[$name] = $result;
                } else {
                    $runs[$name][] = $result;
                }
            }
        }

        $cases = [];
        foreach ($names as $name) {
            $runs[$name][count($runs[$name]) - 1]['body_bytes_on_server'] = Scenarios::sampleBodyBytes($variants[$name]['server']);
            $cases[] = ['label' => $name, 'params' => $variants[$name]['params'], 'runs' => $runs[$name], 'discarded_warmup_run' => $discarded[$name], 'summary' => Harness::summarize($runs[$name])];
            ($variants[$name]['stop'])();
        }

        return [
            'scenario' => 'compare-pushok',
            'description' => "Same {$o['count']} alert notifications (~{$o['payload']} byte body), same mock server delay ({$o['delay']} ms), window/concurrency {$o['window']}, {$o['connections']} connection. One mock server per variant. Round 0 is a discarded warm-up; the order of variants rotates and alternates direction every round. pushok exposes no per-request durations, so latency is not compared.",
            'orders' => $orders,
            'cases' => $cases,
        ];
    }

    private static function newServer(MockServer $server, array $o): void
    {
        $server->settings(['recordLimit' => 50]);
        $server->rules(Scenarios::okRule($o['delay']));
    }

    private static function oursRaw(MockServer $server, array $o): array
    {
        self::newServer($server, $o);
        $body = Kit::payloadBody($o['payload']);
        $profile = Scenarios::profile($server, 'bench-compare', $o['window'], $o['connections']);
        $transport = new CurlTransport(new CurlPoolRegistry);
        foreach ($transport->send(Scenarios::requests($server->baseUrl(), 1, $body), $profile) as $_) {
        }

        return [
            'server' => $server,
            'params' => $o + ['library' => 'padosoft/laravel-mobile-push', 'layer' => 'CurlTransport'],
            'stop' => function () use ($transport, $server): void {
                $transport->close();
                $server->stop();
            },
            'run' => fn () => Harness::measure($server, $rec = new Recorder(false), function (Recorder $rec) use ($transport, $server, $o, $body, $profile): void {
                foreach ($transport->send(Scenarios::requests($server->baseUrl(), $o['count'], $body), $profile) as $exchange) {
                    $rec->exchange($exchange);
                }
            }),
        ];
    }

    private static function oursPipeline(MockServer $server, array $o, bool $warm): array
    {
        self::newServer($server, $o);
        $kit = new Kit;
        $driver = $kit->apnsDriver($server, $o['window'], $o['connections']);
        $message = Kit::message($o['payload']);
        $state = (object) ['transport' => new CurlTransport(new CurlPoolRegistry)];
        $state->sender = $kit->sender($state->transport);
        $consume = function (int $n) use ($state, $driver, $message): \Closure {
            return function (?Recorder $rec = null) use ($n, $state, $driver, $message): void {
                foreach ($state->sender->send($driver, $message, Kit::apnsTokens($n), Kit::options())->results() as $result) {
                    $rec?->answered($result->httpStatus ?? 0);
                }
            };
        };
        $consume(1)();

        return [
            'server' => $server,
            'params' => $o + ['library' => 'padosoft/laravel-mobile-push', 'layer' => 'Sender (apns)', 'warm' => $warm],
            'stop' => function () use ($state, $server): void {
                $state->transport->close();
                $server->stop();
            },
            'run' => function () use ($state, $kit, $server, $consume, $o, $warm) {
                if (! $warm) {
                    $state->transport->close();
                    $state->transport = new CurlTransport(new CurlPoolRegistry);
                    $state->sender = $kit->sender($state->transport);
                }

                return Harness::measure($server, new Recorder(false), $consume($o['count']));
            },
        ];
    }

    private static function pushokClient(MockServer $server, int $window, int $connections, bool $keepAlive): Client
    {
        $port = $server->port();
        $auth = Token::useExisting('benchmark.fake.token', ['key_id' => 'KEY1111111', 'team_id' => Kit::TEAM, 'app_bundle_id' => Kit::BUNDLE]);
        $client = new Client($auth, true, [
            CURLOPT_PORT => $port,
            CURLOPT_RESOLVE => ["api.push.apple.com:{$port}:127.0.0.1"],
            CURLOPT_CAINFO => $server->caBundlePath(),
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROXY => '',
        ]);
        $client->setNbConcurrentRequests($window);
        $client->setMaxConcurrentConnections($connections);
        $client->setAutoCloseConnections(! $keepAlive);

        return $client;
    }

    private static function pushokNotification(string $token, int $padBytes): Notification
    {
        $payload = Payload::create()->setAlert(Alert::create()->setTitle('Shipped')->setBody('On its way'));
        $payload->addCustomValue('pad', str_repeat('x', $padBytes));

        return new Notification($payload, $token);
    }

    private static function pushokPad(int $payloadBytes): int
    {
        $base = strlen(Payload::create()->setAlert(Alert::create()->setTitle('Shipped')->setBody('On its way'))->addCustomValue('pad', '')->toJson());

        return max(0, $payloadBytes - $base);
    }

    private static function pushok(MockServer $server, array $o, bool $keepAlive): array
    {
        self::newServer($server, $o);
        $pad = self::pushokPad($o['payload']);
        $client = self::pushokClient($server, $o['window'], $o['connections'], $keepAlive);
        $push = function (int $n, ?Recorder $rec = null) use (&$client, $pad): void {
            for ($i = 0; $i < $n; $i++) {
                $client->addNotification(self::pushokNotification('d'.sprintf('%063x', $i), $pad));
            }
            foreach ($client->push() as $response) {
                $rec?->answered($response->getStatusCode());
            }
        };
        $push(1);

        return [
            'server' => $server,
            'params' => $o + ['library' => 'edamov/pushok '.self::pushokVersion(), 'keep_alive' => $keepAlive, 'curl_options' => array_keys([CURLOPT_PORT => 1, CURLOPT_RESOLVE => 1, CURLOPT_CAINFO => 1, CURLOPT_SSL_VERIFYHOST => 1, CURLOPT_PROXY => 1])],
            'stop' => function () use (&$client, $server): void {
                $client->close();
                $server->stop();
            },
            'run' => fn () => Harness::measure($server, new Recorder(false), fn (Recorder $rec) => $push($o['count'], $rec)),
        ];
    }

    private static function pushokVersion(): string
    {
        $v = \Composer\InstalledVersions::getPrettyVersion('edamov/pushok');
        $r = \Composer\InstalledVersions::getReference('edamov/pushok');

        return ($v ?? '?').' ('.substr((string) $r, 0, 10).')';
    }

    private static function pushokOptionsDescription(): array
    {
        return [
            'version' => self::pushokVersion(),
            'client_constructor' => 'new Client(Token::useExisting("benchmark.fake.token", [...]), true, $curlOptions)',
            'curl_options' => [
                'CURLOPT_PORT' => 'mock port (pushok sets 443; user options win: `$this->curlOptions + $request->getOptions()`)',
                'CURLOPT_RESOLVE' => '["api.push.apple.com:PORT:127.0.0.1"]',
                'CURLOPT_CAINFO' => "the mock's CA bundle (peer verification stays on)",
                'CURLOPT_SSL_VERIFYHOST' => '0 because the mock certificate is for localhost/127.0.0.1, not api.push.apple.com. This library run therefore skips the hostname check; this package always checks it. The cost of that check is negligible next to the handshake itself, but it is a difference.',
                'CURLOPT_PROXY' => '"" (the container sets HTTPS_PROXY, pushok does not disable it)',
            ],
            'library_settings' => 'setNbConcurrentRequests(window), setMaxConcurrentConnections(connections), setAutoCloseConnections(false) for the warm variant (library default is true)',
            'not_set' => 'everything else is the library default (CURLOPT_TIMEOUT 10, HTTP/2, CURLMOPT_PIPELINING=MULTIPLEX, MAX_HOST_CONNECTIONS=connections; no CURLOPT_PIPEWAIT)',
            'queueing' => 'notifications are added with addNotification() in a loop and held in memory until push(); addNotifications() was not used because it does an in_array() scan per notification (quadratic)',
        ];
    }

    // ------------------------------------------------------------------ behaviour

    private static function behaviour(): array
    {
        $server = MockServer::start();
        try {
            return [
                'versions' => ['pushok' => self::pushokVersion(), 'package_commit' => Harness::env()['package_commit'], 'libcurl' => curl_version()['version']],
                'cases' => [
                    self::dropOnceReused($server),
                    self::dropOnceFresh($server),
                    self::dropAlwaysInBatch($server),
                    self::serverUnavailable($server),
                    self::unavailableOnceThenOk($server),
                    self::unregistered($server),
                    self::cutMidBody($server),
                ],
            ];
        } finally {
            $server->stop();
        }
    }

    private static function tok(string $prefix, int $i): string
    {
        return $prefix.sprintf('%063x', $i);
    }

    /** @return array{received: int, processed: int} */
    private static function serverCount(MockServer $server, string $token): array
    {
        $r = $p = 0;
        foreach ($server->requests() as $req) {
            if (str_ends_with($req['path'], $token)) {
                $r++;
                $p += $req['processed'] ? 1 : 0;
            }
        }

        return ['received' => $r, 'processed' => $p];
    }

    private static function pushokPush(MockServer $server, array $tokens, ?Client $client = null): array
    {
        $client ??= self::pushokClient($server, 20, 1, true);
        foreach ($tokens as $t) {
            $client->addNotification(self::pushokNotification($t, 0));
        }
        try {
            $out = [];
            foreach ($client->push() as $r) {
                $out[] = ['token' => substr((string) $r->getDeviceToken(), 0, 2).'..'.substr((string) $r->getDeviceToken(), -3), 'status' => $r->getStatusCode(), 'reason' => $r->getErrorReason(), 'apns_id' => $r->getApnsId(), 'ts410' => $r->get410Timestamp()];
            }

            return ['returned' => $out];
        } catch (\Throwable $e) {
            return ['threw' => $e::class.': '.$e->getMessage()];
        } finally {
            $client->close();
        }
    }

    private static function oursSend(MockServer $server, array $tokens, ?callable $between = null): array
    {
        $kit = new Kit;
        $driver = $kit->apnsDriver($server, 20, 1);
        $sender = $kit->sender($transport = new CurlTransport(new CurlPoolRegistry));
        $message = PushMessage::make('Shipped', 'On its way');
        $out = [];
        foreach ($sender->send($driver, $message, (function () use ($tokens) {
            foreach ($tokens as $t) {
                yield \Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken::of($t);
            }
        })(), Kit::options())->results() as $r) {
            $t = $r->destination->value();
            $out[] = ['token' => substr($t, 0, 2).'..'.substr($t, -3), 'status' => $r->status->value, 'reason' => $r->reason->code->value, 'http' => $r->httpStatus, 'attempts' => $r->attempts, 'destination_action' => $r->destinationAction->value, 'invalidated_at_ms' => $r->invalidatedAtMs, 'next_attempt' => $r->nextAttemptAt !== null];
        }
        $transport->close();

        return ['returned' => $out];
    }

    private static function reset(MockServer $server, array ...$rules): void
    {
        $server->reset();
        $server->settings(['recordLimit' => 1000]);
        $server->rules(...$rules);
    }

    private static function counts(MockServer $server, array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $out[substr($t, 0, 2).'..'.substr($t, -3)] = self::serverCount($server, $t);
        }

        return $out;
    }

    private static function case(string $name, string $setup, array $tokens, MockServer $server, array $ours, array $pushok, array $oursServer, array $pushokServer): array
    {
        return ['name' => $name, 'setup' => $setup, 'ours' => $ours + ['server_counts' => $oursServer], 'pushok' => $pushok + ['server_counts' => $pushokServer]];
    }

    private static function dropOnceReused(MockServer $server): array
    {
        [$warm, $lost] = [self::tok('d', 1), self::tok('e', 2)];
        $rule = fn () => ['path' => '/'.$lost.'$', 'action' => 'drop', 'times' => 1];
        $ok = Scenarios::okRule(0);

        self::reset($server, $rule(), $ok);
        $o = self::oursSend($server, [$warm, $lost]);
        $os = self::counts($server, [$warm, $lost]);

        self::reset($server, $rule(), $ok);
        $client = self::pushokClient($server, 20, 1, true);
        $client->addNotification(self::pushokNotification($warm, 0));
        $client->push();   // connection now open and reusable (keep-alive client)
        $client->addNotification(self::pushokNotification($lost, 0));
        $pushok = [];
        try {
            foreach ($client->push() as $r) {
                $pushok['returned'][] = ['status' => $r->getStatusCode(), 'reason' => $r->getErrorReason(), 'apns_id' => $r->getApnsId()];
            }
        } catch (\Throwable $e) {
            $pushok['threw'] = $e::class.': '.$e->getMessage();
        }
        $client->close();
        $ps = self::counts($server, [$warm, $lost]);

        return self::case('processed-then-dropped, once, on a reused connection', 'Request 1 (200) opens the connection; request 2 is processed by the server, which then destroys the TCP connection without answering (rule `drop`, times 1; the server answers 200 to the same request if it arrives again).', [$warm, $lost], $server, ['returned' => array_slice($o['returned'], -1)], $pushok, $os, $ps);
    }

    private static function dropOnceFresh(MockServer $server): array
    {
        $lost = self::tok('e', 3);
        $rules = [['path' => '/'.$lost.'$', 'action' => 'drop', 'times' => 1], Scenarios::okRule(0)];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, [$lost]);
        $os = self::counts($server, [$lost]);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, [$lost]);
        $ps = self::counts($server, [$lost]);

        return self::case('processed-then-dropped, once, as the first request on a new connection', 'Same rule, but nothing was sent on the connection before.', [$lost], $server, $o, $p, $os, $ps);
    }

    private static function dropAlwaysInBatch(MockServer $server): array
    {
        $good = [self::tok('d', 10), self::tok('d', 11), self::tok('d', 12), self::tok('d', 13)];
        $lost = self::tok('e', 14);
        $tokens = [$good[0], $good[1], $lost, $good[2], $good[3]];
        $rules = [['path' => '/'.$lost.'$', 'action' => 'drop'], Scenarios::okRule(0)];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, $tokens);
        $os = self::counts($server, $tokens);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, $tokens);
        $ps = self::counts($server, $tokens);

        return self::case('a batch of 5 where one request is always processed-then-dropped', 'Five notifications in one call; the third is processed and its connection destroyed every time it is sent.', $tokens, $server, $o, $p, $os, $ps);
    }

    private static function serverUnavailable(MockServer $server): array
    {
        $t = self::tok('d', 20);
        $rules = [['action' => 'respond', 'status' => 503, 'headers' => ['content-type' => 'application/json'], 'body' => '{"reason":"ServiceUnavailable"}']];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, [$t]);
        $os = self::counts($server, [$t]);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, [$t]);
        $ps = self::counts($server, [$t]);

        return self::case('HTTP 503 ServiceUnavailable', 'The server answers 503 {"reason":"ServiceUnavailable"} to every request.', [$t], $server, $o, $p, $os, $ps);
    }

    private static function unavailableOnceThenOk(MockServer $server): array
    {
        $t = self::tok('d', 21);
        $rules = [['action' => 'respond', 'status' => 503, 'headers' => ['content-type' => 'application/json'], 'body' => '{"reason":"ServiceUnavailable"}', 'times' => 1], Scenarios::okRule(0)];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, [$t]);
        $os = self::counts($server, [$t]);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, [$t]);
        $ps = self::counts($server, [$t]);

        return self::case('HTTP 503 once, then 200', 'The first request gets 503, any later one gets 200.', [$t], $server, $o, $p, $os, $ps);
    }

    private static function unregistered(MockServer $server): array
    {
        $t = self::tok('d', 22);
        $rules = [['action' => 'respond', 'status' => 410, 'headers' => ['content-type' => 'application/json'], 'body' => '{"reason":"Unregistered","timestamp":1763600000123}']];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, [$t]);
        $os = self::counts($server, [$t]);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, [$t]);
        $ps = self::counts($server, [$t]);

        return self::case('HTTP 410 Unregistered with timestamp', 'The server answers 410 {"reason":"Unregistered","timestamp":1763600000123}.', [$t], $server, $o, $p, $os, $ps);
    }

    private static function cutMidBody(MockServer $server): array
    {
        $t = self::tok('d', 23);
        $rules = [['action' => 'close-mid-body', 'status' => 200]];

        self::reset($server, ...$rules);
        $o = self::oursSend($server, [$t]);
        $os = self::counts($server, [$t]);
        self::reset($server, ...$rules);
        $p = self::pushokPush($server, [$t]);
        $ps = self::counts($server, [$t]);

        return self::case('response cut in the middle of the body', 'The server sends 200 and the first half of a 2 KiB body, then destroys the connection.', [$t], $server, $o, $p, $os, $ps);
    }

    private static function formatBehaviour(array $b): string
    {
        $out = "pushok {$b['versions']['pushok']}; libcurl {$b['versions']['libcurl']}\n";
        foreach ($b['cases'] as $c) {
            $out .= "\n* {$c['name']}\n";
            foreach (['ours', 'pushok'] as $who) {
                $out .= "  {$who}: ".json_encode(array_diff_key($c[$who], ['server_counts' => 1]), JSON_UNESCAPED_SLASHES)."\n";
                $out .= "     server: ".json_encode($c[$who]['server_counts'], JSON_UNESCAPED_SLASHES)."\n";
            }
        }

        return $out;
    }
}
