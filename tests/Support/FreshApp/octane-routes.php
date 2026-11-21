
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
