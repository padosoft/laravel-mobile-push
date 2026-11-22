<?php

declare(strict_types=1);

/*
 * Story: one worker creates guard state on the `file` cache store (the first push to a person creates that person's
 * frequency counter, the first badge creates the badge counter) while six other workers read the same key.
 *
 * Laravel's file store creates a cache file empty and only then locks and fills it; a reader that meets the empty
 * file takes it for expired and deletes it, and the value being written is gone. Measured on the store alone: 2,511
 * of 7,188 freshly created entries lost with six readers. In the package it showed as 6 pushes through a cap of 5
 * and as badge numbers handed out twice, once each in CI and never locally, until this scenario isolated it.
 *
 * The guard store takes the key's lock for reads too on that store. The oracle is the writer's own read-back, a
 * moment after each creation: every entry it created must still be there.
 */

require_once __DIR__.'/CoordinationKit.php';

it('no guard entry created on the file store is deleted by the processes that read it at that instant', function (): void {
    $dir = sys_get_temp_dir().'/mp-file-race-'.bin2hex(random_bytes(5));
    mkdir($dir.'/cache', 0700, true);
    $args = [
        'store' => 'file', 'cacheDir' => $dir.'/cache', 'announce' => $dir.'/current',
        'appKey' => 'base64:'.base64_encode(random_bytes(32)), 'now' => '2026-11-20T10:00:00Z',
    ];
    $start = static function (array $args) {
        $process = proc_open([PHP_BINARY, __DIR__.'/guard-file-race.php', json_encode($args, JSON_THROW_ON_ERROR)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('cannot start a process');
        }

        return [$process, $pipes];
    };
    $finish = static function (array $started): array {
        [$process, $pipes] = $started;
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $line = json_decode(trim($out), true);
        if ($code !== 0 || ! is_array($line)) {
            throw new RuntimeException("process failed ({$code}): ".substr($out.$err, 0, 2000));
        }

        return $line;
    };

    try {
        $readers = [];
        for ($i = 0; $i < 6; $i++) {
            $readers[] = $start(['role' => 'reader', 'seconds' => 4.0] + $args);
        }
        $writer = $finish($start(['role' => 'writer', 'seconds' => 2.5] + $args));
        $reads = array_sum(array_column(array_map($finish, $readers), 'reads'));
    } finally {
        removeTree($dir);
    }

    if (getenv('MP_RUNTIME_REPORT') !== false) {
        fwrite(STDERR, sprintf("[file creation race] created=%d lost=%d refused=%d reads=%d\n", $writer['created'], $writer['lost'], $writer['refused'], $reads));
    }
    expect($writer['lost'])->toBe(0)
        ->and($writer['created'])->toBeGreaterThan(20)   // the race was really run
        ->and($reads)->toBeGreaterThan(100);
});
