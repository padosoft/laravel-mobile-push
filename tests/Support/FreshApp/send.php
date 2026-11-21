// Run with: php artisan tinker --execute="$(cat send.php)"   (no opening PHP tag: tinker evaluates it)
// Everything is simulated: APP_ENV is not production and the mode is "auto".
use App\Models\User;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$statuses = static fn (iterable $results): array => collect($results)->map(fn ($r) => $r->status->value)->all();
$must = static function (bool $ok, string $what): void {
    if (! $ok) {
        throw new RuntimeException('FRESH-APP SMOKE FAILED: '.$what);
    }
    echo "ok: {$what}\n";
};
$ios = str_repeat('ab', 32);
$android = 'fcm-registration-token-'.str_repeat('x', 120);

$must($statuses(MobilePush::toToken(Platform::Ios, $ios)->send(PushMessage::make('Order shipped', 'Arrives Friday'))->results()) === ['simulated'], 'a raw iOS token is simulated');
$must($statuses(mobile_push_to_token(Platform::Android, $android, 'Order shipped', 'Arrives Friday')->results()) === ['simulated'], 'the helper sends to a raw Android token');

$user = User::firstOrCreate(['email' => 'alice@example.test'], ['name' => 'Alice', 'password' => bcrypt('secret')]);
$user->registerPushDevice(MobilePush::binding('ios'), ApnsDeviceToken::of($ios));
$user->registerPushDevice(MobilePush::binding('android'), FcmToken::of($android));
$must($statuses(MobilePush::to($user)->send(PushMessage::make('Hello Alice', 'Two devices')->data(['order' => '42']))->results()) === ['simulated', 'simulated'], 'a user with two registered devices gets two results');
$must(! DB::table('mobile_push_devices')->where('token', 'like', '%abab%')->exists(), 'device tokens are not stored in clear');

MobilePush::to($user)->queue(PushMessage::make('Queued', 'Through the ledger'));
$must(DB::table('mobile_push_deliveries')->where('state', 'pending')->count() === 2, 'the durable queue wrote two pending deliveries');
$must(Queue::size('push') >= 1, 'a relay job is waiting for the worker (queue driver: '.config('queue.default').')');
