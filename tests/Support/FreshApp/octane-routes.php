
// Appended to routes/web.php by the "runtimes" CI job: one push per HTTP request, under Octane.
Route::get('/push/{n}', function (int $n) {
    $token = sprintf('%064x', $n);
    $results = [];
    foreach (\Padosoft\MobilePush\Laravel\Facades\MobilePush::toToken(\Padosoft\MobilePush\Core\Identity\Platform::Ios, $token)
        ->send(\Padosoft\MobilePush\Core\Message\PushMessage::make('Request '.$n, 'Under Octane'))->results() as $result) {
        $results[] = $result->status->value;
    }

    return response()->json(['n' => $n, 'pid' => getmypid(), 'statuses' => $results, 'scope' => \Padosoft\MobilePush\Laravel\Facades\MobilePush::currentScope()]);
});

// One request on the wire per HTTP request, through the application's real transport (libcurl and its per-process
// connection pool) to the local HTTP/2 mock started by wire-mock.php. The mock's counters say whether the long-lived
// workers kept their connection between requests.
Route::get('/wire/{n}', function (int $n) {
    $mock = json_decode((string) file_get_contents((string) env('MOBILE_PUSH_WIRE_MOCK')), true, 8, JSON_THROW_ON_ERROR);
    $profile = new \Padosoft\MobilePush\Core\Transport\ConnectionProfile(poolKey: 'wire-check', connectTimeoutMs: 2000, timeoutMs: 5000, caBundlePath: $mock['ca']);
    $request = new \Padosoft\MobilePush\Core\Transport\Request('w'.$n, 'POST', $mock['baseUrl'].'/w/'.$n, ['content-type' => 'application/json'], '{"aps":{"alert":"wire"}}');
    $out = ['n' => $n, 'pid' => getmypid(), 'sapi' => PHP_SAPI, 'thread' => function_exists('zend_thread_id') ? zend_thread_id() : 0];
    foreach (app(\Padosoft\MobilePush\Core\Transport\Transport::class)->send([$request], $profile) as $exchange) {
        $out['status'] = $exchange->response?->status;
        $out['http'] = $exchange->response?->httpVersion;
        $out['failure'] = $exchange->failure?->message;
    }

    return response()->json($out);
});

// Several requests on the wire inside ONE HTTP request (one send() call): they share the connection whatever the
// runtime, including PHP-FPM, where nothing survives the request.
Route::get('/wire-batch/{n}/{count}', function (int $n, int $count) {
    $mock = json_decode((string) file_get_contents((string) env('MOBILE_PUSH_WIRE_MOCK')), true, 8, JSON_THROW_ON_ERROR);
    $profile = new \Padosoft\MobilePush\Core\Transport\ConnectionProfile(poolKey: 'wire-check', connectTimeoutMs: 2000, timeoutMs: 5000, caBundlePath: $mock['ca']);
    $requests = [];
    for ($i = 0; $i < $count; $i++) {
        $requests[] = new \Padosoft\MobilePush\Core\Transport\Request('b'.$n.'-'.$i, 'POST', $mock['baseUrl'].'/b/'.$n.'/'.$i, ['content-type' => 'application/json'], '{"aps":{"alert":"wire"}}');
    }
    $ok = 0;
    foreach (app(\Padosoft\MobilePush\Core\Transport\Transport::class)->send($requests, $profile) as $exchange) {
        $ok += $exchange->response?->status === 200 && $exchange->response->httpVersion === '2' ? 1 : 0;
    }

    return response()->json(['n' => $n, 'pid' => getmypid(), 'sapi' => PHP_SAPI, 'ok' => $ok, 'of' => $count]);
});

// Under Swoole: the coroutine id of the request and the hook flags of the worker (0 and null elsewhere).
Route::get('/hooks', fn () => response()->json([
    'coroutine' => class_exists(\Swoole\Coroutine::class) ? \Swoole\Coroutine::getCid() : 0,
    'hook_flags' => class_exists(\Swoole\Runtime::class) ? \Swoole\Runtime::getHookFlags() : null,
    'native_curl' => defined('SWOOLE_HOOK_NATIVE_CURL') ? SWOOLE_HOOK_NATIVE_CURL : null,
    'curl' => defined('SWOOLE_HOOK_CURL') ? SWOOLE_HOOK_CURL : null,
]));
