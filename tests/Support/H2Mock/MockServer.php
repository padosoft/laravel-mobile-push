<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Support\H2Mock;

/**
 * Starts and drives tests/Support/H2Mock/server.mjs.
 *
 * Key material (CA, server certificate, optional client certificate) is generated at runtime with
 * PHP's openssl functions into a private temp directory that is deleted on stop(). Nothing is stored in the repo.
 *
 * Start one server per test file (beforeAll/afterAll) and call reset() between tests.
 */
final class MockServer
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $dir;

    private int $port = 0;

    private int $controlPort = 0;

    private ?int $proxyPort = null;

    private ?string $clientCertPem = null;

    private ?string $clientKeyPem = null;

    private bool $stopped = false;

    private function __construct()
    {
        $this->dir = sys_get_temp_dir().'/h2mock-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    public static function available(): bool
    {
        if (! function_exists('proc_open') || ! extension_loaded('openssl')) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec('node --version 2>/dev/null', $out, $code);

        return $code === 0 && isset($out[0]) && (int) ltrim($out[0], 'v') >= 18;
    }

    /** Message for skipped tests. */
    public static function unavailableReason(): string
    {
        return 'Node.js >= 18 (with proc_open and ext-openssl) is required for the HTTP/2 mock server.';
    }

    /**
     * @param  array{mode?: 'h2'|'h2+h1'|'h1', mtls?: bool, maxConcurrentStreams?: int, recordLimit?: int, proxy?: bool}  $options
     */
    public static function start(array $options = []): self
    {
        $server = new self;
        $server->boot($options);

        return $server;
    }

    public function baseUrl(): string
    {
        return 'https://localhost:'.$this->port;
    }

    /** Same server, addressed by IP (the certificate SAN covers 127.0.0.1 too). */
    public function ipBaseUrl(): string
    {
        return 'https://127.0.0.1:'.$this->port;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function caBundlePath(): string
    {
        return $this->dir.'/ca.pem';
    }

    /** A CA bundle that did NOT sign the server certificate (for TLS failure tests). */
    public function foreignCaBundlePath(): string
    {
        $path = $this->dir.'/foreign-ca.pem';
        if (! is_file($path)) {
            [$cert] = self::makeCa($this->dir, 'h2mock foreign CA');
            file_put_contents($path, $cert);
        }

        return $path;
    }

    public function proxyUrl(): string
    {
        if ($this->proxyPort === null) {
            throw new \LogicException('Start the server with ["proxy" => true].');
        }

        return 'http://127.0.0.1:'.$this->proxyPort;
    }

    public function clientCertificatePem(): string
    {
        return $this->clientCertPem ?? throw new \LogicException('Start the server with ["mtls" => true].');
    }

    public function clientKeyPem(): string
    {
        return $this->clientKeyPem ?? throw new \LogicException('Start the server with ["mtls" => true].');
    }

    /**
     * Replace the rule list (see server.mjs for the rule format).
     *
     * @param  array<string, mixed>  ...$rules
     */
    public function rules(array ...$rules): self
    {
        $this->control('PUT', '/rules', ['rules' => array_values($rules)]);

        return $this;
    }

    /**
     * Append one rule.
     *
     * @param  array<string, mixed>  $rule
     */
    public function rule(array $rule): self
    {
        $this->control('POST', '/rules', ['rules' => [$rule]]);

        return $this;
    }

    /** @param array{maxConcurrentStreams?: int, recordLimit?: int} $settings */
    public function settings(array $settings): self
    {
        $this->control('POST', '/settings', $settings);

        return $this;
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->control('GET', '/stats');
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string, session: string, stream: ?int, protocol: string, action: string, processed: bool}> */
    public function requests(): array
    {
        /** @var list<array{method: string, path: string, headers: array<string, string>, body: string, session: string, stream: ?int, protocol: string, action: string, processed: bool}> */
        return $this->control('GET', '/requests');
    }

    /** Clears rules, counters and recorded requests and closes every open connection. */
    public function reset(): self
    {
        $this->control('POST', '/reset');

        return $this;
    }

    /** Destroys every open connection, keeps counters. */
    public function closeSessions(): self
    {
        $this->control('POST', '/close-sessions');

        return $this;
    }

    /** Polls stats until $predicate is true or $timeoutMs elapses; returns the last stats. */
    public function waitFor(callable $predicate, int $timeoutMs = 2000): array
    {
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        do {
            $stats = $this->stats();
            if ($predicate($stats)) {
                return $stats;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        return $stats;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $options */
    private function boot(array $options): void
    {
        [$caCert, $caKey] = self::makeCa($this->dir, 'h2mock test CA');
        file_put_contents($this->dir.'/ca.pem', $caCert);
        [$cert, $key] = self::makeLeaf($this->dir, $caCert, $caKey, 'localhost', 'server');
        file_put_contents($this->dir.'/server.pem', $cert);
        file_put_contents($this->dir.'/server.key', $key);
        chmod($this->dir.'/server.key', 0600);

        $mtls = (bool) ($options['mtls'] ?? false);
        if ($mtls) {
            [$this->clientCertPem, $this->clientKeyPem] = self::makeLeaf($this->dir, $caCert, $caKey, 'h2mock client', 'client');
        }

        $config = [
            'certPath' => $this->dir.'/server.pem',
            'keyPath' => $this->dir.'/server.key',
            'caPath' => $this->dir.'/ca.pem',
            'mode' => $options['mode'] ?? 'h2',
            'requireClientCert' => $mtls,
            'maxConcurrentStreams' => $options['maxConcurrentStreams'] ?? 1000,
            'recordLimit' => $options['recordLimit'] ?? 1000,
            'proxy' => (bool) ($options['proxy'] ?? false),
        ];

        $process = proc_open(
            ['node', __DIR__.'/server.mjs', json_encode($config, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', getenv('H2MOCK_STDERR') ?: $this->dir.'/stderr.log', 'a']],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the HTTP/2 mock server (node).');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $line = '';
        $deadline = microtime(true) + 10;
        stream_set_blocking($pipes[1], false);
        while (! str_contains($line, "\n") && microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $w = $e = null;
            if (stream_select($read, $w, $e, 0, 100_000) > 0) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                    break;
                }
                $line .= $chunk;
            }
        }
        $ready = json_decode(trim($line), true);
        if (! is_array($ready) || ($ready['ready'] ?? false) !== true) {
            $stderr = (string) @file_get_contents($this->dir.'/stderr.log');
            $this->stop();
            throw new \RuntimeException('HTTP/2 mock server did not start: '.$stderr);
        }
        $this->port = (int) $ready['port'];
        $this->controlPort = (int) $ready['controlPort'];
        $this->proxyPort = isset($ready['proxyPort']) ? (int) $ready['proxyPort'] : null;

        register_shutdown_function(function (): void {
            $this->stop();
        });
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    private function control(string $method, string $path, ?array $body = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\nConnection: close\r\n",
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $raw = @file_get_contents('http://127.0.0.1:'.$this->controlPort.$path, false, $context);
        if ($raw === false) {
            throw new \RuntimeException("Mock control API unreachable: {$method} {$path}");
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function openSslConfig(string $dir): string
    {
        $path = $dir.'/openssl.cnf';
        if (! is_file($path)) {
            file_put_contents($path, <<<'CNF'
                [ req ]
                distinguished_name = dn
                [ dn ]
                [ v3_ca ]
                basicConstraints = critical, CA:TRUE
                keyUsage = critical, keyCertSign, cRLSign
                subjectKeyIdentifier = hash
                [ v3_server ]
                basicConstraints = critical, CA:FALSE
                keyUsage = critical, digitalSignature, keyEncipherment
                extendedKeyUsage = serverAuth
                subjectAltName = DNS:localhost, IP:127.0.0.1
                [ v3_client ]
                basicConstraints = critical, CA:FALSE
                keyUsage = critical, digitalSignature, keyEncipherment
                extendedKeyUsage = clientAuth
                CNF);
        }

        return $path;
    }

    /** @return array{0: string, 1: \OpenSSLAsymmetricKey} */
    private static function makeCa(string $dir, string $name): array
    {
        $cnf = self::openSslConfig($dir);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key instanceof \OpenSSLAsymmetricKey);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
        assert($csr instanceof \OpenSSLCertificateSigningRequest);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['config' => $cnf, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        assert($cert instanceof \OpenSSLCertificate);
        openssl_x509_export($cert, $pem);

        return [$pem, $key];
    }

    /** @return array{0: string, 1: string} PEM certificate and PEM private key */
    private static function makeLeaf(string $dir, string $caCert, \OpenSSLAsymmetricKey $caKey, string $cn, string $kind): array
    {
        $cnf = self::openSslConfig($dir);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key instanceof \OpenSSLAsymmetricKey);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
        assert($csr instanceof \OpenSSLCertificateSigningRequest);
        $cert = openssl_csr_sign($csr, $caCert, $caKey, 2, ['config' => $cnf, 'x509_extensions' => 'v3_'.$kind, 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        assert($cert instanceof \OpenSSLCertificate);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);

        return [$certPem, $keyPem];
    }
}
