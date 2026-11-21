// Run with: php artisan tinker --execute="$(cat campaign.php)"   (no opening PHP tag: tinker evaluates it)
// A campaign to 250 registered devices, planned in slices of 100, on the application's real queue backend.
// Everything is simulated: APP_ENV is not production and the mode is "auto".
use App\Models\User;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$must = static function (bool $ok, string $what): void {
    if (! $ok) {
        throw new RuntimeException('FRESH-APP SMOKE FAILED: '.$what);
    }
    echo "ok: {$what}\n";
};

// Run on a fresh schema (php artisan migrate:fresh): the counts below are exact.
$binding = MobilePush::binding('ios');
foreach (User::factory()->count(250)->create() as $i => $user) {
    $user->registerPushDevice($binding, ApnsDeviceToken::of(sprintf('%064x', 0xC0FFEE00000 + $i)));
}
$must(DB::table('mobile_push_devices')->count() === 250, '250 devices are registered, one per user');

$campaign = MobilePush::campaign(PushMessage::make('Black Friday', '-40% today'))
    ->name('smoke-campaign')->via('ios')->audience(DeviceAudience::all())->dispatch();
file_put_contents(storage_path('campaign-id'), $campaign->id);
$must($campaign->status->value === 'planning', 'the campaign is planning (queue driver: '.config('queue.default').')');
$waiting = 0;
foreach (['push-bulk', 'push'] as $queue) {
    try {
        $waiting += Queue::size($queue);
    } catch (Throwable $e) {
        // beanstalkd: a tube that never held a job does not exist, and asking for its size throws
        if (! str_contains($e::class, 'TubeNotFound')) {
            throw $e;
        }
    }
}
$must($waiting >= 1, 'a planner job is waiting for the worker');
