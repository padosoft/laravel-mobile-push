// Run after the queue worker, with: php artisan tinker --execute="$(cat verify.php)"
$must = static function (bool $ok, string $what): void {
    if (! $ok) {
        throw new RuntimeException('FRESH-APP SMOKE FAILED: '.$what);
    }
    echo "ok: {$what}\n";
};
$states = DB::table('mobile_push_deliveries')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
$must($states === ['simulated' => 2], 'the worker relayed both deliveries (states: '.json_encode($states).')');
$must(Queue::size('push') === 0, 'no job is left in the queue');
$must(DB::table('mobile_push_logs')->count() >= 5, 'the database log sink recorded the sends');
$must(! DB::table('mobile_push_logs')->where('destination_masked', 'like', '%'.str_repeat('ab', 20).'%')->exists(), 'the log holds masked destinations only');
