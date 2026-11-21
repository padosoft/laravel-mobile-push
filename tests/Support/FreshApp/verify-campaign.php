// Run after the queue worker, with: php artisan tinker --execute="$(cat verify-campaign.php)"
use Padosoft\MobilePush\Laravel\Campaigns\CampaignManager;

$must = static function (bool $ok, string $what): void {
    if (! $ok) {
        throw new RuntimeException('FRESH-APP SMOKE FAILED: '.$what);
    }
    echo "ok: {$what}\n";
};
$id = trim((string) file_get_contents(storage_path('campaign-id')));
$progress = app(CampaignManager::class)->progress('default', $id);
$must($progress->campaign->status->value === 'completed', 'the campaign ended completed (status: '.$progress->campaign->status->value.')');
$must($progress->simulated === 250 && $progress->waiting === 0 && $progress->inFlight === 0, 'every one of the 250 destinations was relayed once (simulated: '.$progress->simulated.')');
$must(DB::table('mobile_push_deliveries')->where('campaign_id', $id)->distinct()->count('device_id') === 250, 'one delivery per device, no duplicate');
$must(Queue::size('push') + Queue::size('push-bulk') === 0, 'no job is left in the queue');
