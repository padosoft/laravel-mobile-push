// Run after Horizon processed the queue, with: php artisan tinker --execute="$(cat verify-horizon.php)"
$must = static function (bool $ok, string $what): void {
    if (! $ok) {
        throw new RuntimeException('FRESH-APP SMOKE FAILED: '.$what);
    }
    echo "ok: {$what}\n";
};
$jobs = app(Laravel\Horizon\Contracts\JobRepository::class)->getCompleted();
$must($jobs->count() >= 1, 'Horizon recorded the completed relay jobs ('.$jobs->count().')');
$payload = (string) $jobs->first()->payload;
$tags = json_decode($payload, true)['tags'] ?? [];
$must(in_array('mobile-push', $tags, true) && in_array('relay', $tags, true), 'the jobs carry the package tags ('.json_encode($tags).')');
$must(count(array_filter($tags, static fn ($t) => str_starts_with((string) $t, 'effect:'))) === 1, 'one tag names the effect');
$must(! str_contains($payload, str_repeat('ab', 20)), 'no device token is visible in a job payload');
$states = DB::table('mobile_push_deliveries')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
$must($states === ['simulated' => 2], 'Horizon relayed both deliveries (states: '.json_encode($states).')');
$must(Queue::size('push') === 0, 'no job is left in the queue');
