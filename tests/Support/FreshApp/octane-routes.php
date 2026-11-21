
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
    $out = ['n' => $n, 'pid' => getmypid(), 'thread' => function_exists('zend_thread_id') ? zend_thread_id() : 0];
    foreach (app(\Padosoft\MobilePush\Core\Transport\Transport::class)->send([$request], $profile) as $exchange) {
        $out['status'] = $exchange->response?->status;
        $out['http'] = $exchange->response?->httpVersion;
        $out['failure'] = $exchange->failure?->message;
    }

    return response()->json($out);
});
