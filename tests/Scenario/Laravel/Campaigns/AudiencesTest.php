<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceReach;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceRegistry;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceSlice;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationFileAudience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationListAudience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\KeysetCursor;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/CampaignWorld.php';

/*
 * What is in the audience: duplicates, the stable upper bound, fan-out targets, an import file.
 */

beforeEach(function (): void {
    $this->w = CampaignWorld::boot(['campaigns.slice' => 100]);
});

it('the same device listed three times in a list audience: one delivery, one request', function (): void {
    $device = app(DeviceRegistrar::class)->register(SendKit::apnsBinding(), CampaignWorld::token(7), null);
    $audience = DestinationListAudience::of([
        CampaignWorld::token(1), CampaignWorld::token(1), CampaignWorld::token(1),
        $device->destination(), $device->destination(), $device->destination(),
        CampaignWorld::token(2),
    ]);

    $campaign = $this->w->dispatch($audience);
    $this->w->drain();

    expect(CampaignWorld::deliveries($campaign->id))->toBe(3)
        ->and(CampaignWorld::states($campaign->id))->toBe(['accepted' => 3])
        ->and([$this->w->requestsFor(1), $this->w->requestsFor(2), $this->w->requestsFor(7)])->toBe([1, 1, 1])
        ->and($this->w->queue->transport->sentCount())->toBe(3)
        ->and($this->w->campaigns()->progress('default', $campaign->id)->campaign->planned)->toBe(3)
        // The registry device stays a registry reference: the token is not copied into the ledger.
        ->and(DB::table('mobile_push_deliveries')->whereNotNull('device_id')->whereNull('destination')->count())->toBe(1);
});

it('a device registered after the campaign started is not included (stable upper bound)', function (): void {
    CampaignWorld::seedDevices(250);
    $campaign = $this->w->dispatch(DeviceAudience::all());
    $this->w->drain(1);                       // first slice planned
    CampaignWorld::seedDevices(50, 250);      // registered while the campaign runs
    $this->w->drain();

    expect(CampaignWorld::deliveries($campaign->id))->toBe(250)
        ->and($this->w->requestHistogram(250))->toBe([0 => 0, 1 => 250, 'many' => 0])
        ->and($this->w->requestHistogram(50, 250))->toBe([0 => 50, 1 => 0, 'many' => 0])
        ->and(CampaignWorld::row($campaign->id)->upper_bound)->toBe('id:250');
});

it('a device that leaves the registry before its slice is not planned; one that becomes inactive after planning is skipped at send time', function (): void {
    CampaignWorld::seedDevices(300);
    $campaign = $this->w->dispatch(DeviceAudience::all());
    $this->w->drain(1);   // devices 1..100 planned, not sent yet
    DB::table('mobile_push_devices')->whereIn('id', [50, 250])->update(['state' => 'invalidated']);
    $this->w->drain();

    expect(CampaignWorld::deliveries($campaign->id))->toBe(299)
        ->and(CampaignWorld::states($campaign->id))->toBe(['accepted' => 298, 'skipped' => 1])
        ->and($this->w->requestsFor(49) + $this->w->requestsFor(249))->toBe(0);
});

it('a topic campaign is one delivery and one request, and the progress report does not pretend to know recipients', function (): void {
    $this->w->answer(static fn (Request $r) => str_contains($r->url, 'fcm.googleapis.com') ? SendKit::fcmOk() : null);
    $campaign = $this->w->dispatch(DestinationListAudience::of([FcmTopic::of('black-friday')]), via: 'android');
    $this->w->drain();

    $progress = $this->w->campaigns()->progress('default', $campaign->id);
    $requests = $this->w->queue->transport->requests();
    expect($requests)->toHaveCount(1)
        ->and(json_decode($requests[0]->body, true)['message']['topic'])->toBe('black-friday')
        ->and($progress->total())->toBe(1)
        ->and($progress->accepted)->toBe(1)
        ->and($progress->recipientsKnown)->toBeFalse()
        ->and($progress->funnel)->toBeNull()
        ->and($progress->campaign->status)->toBe(CampaignStatus::Completed)
        ->and(implode(' ', $progress->notes))->toContain('the number of recipients is not known');
});

it('an import file: read lazily, malformed lines counted and skipped, lines appended after the start not included', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mp-campaign-');
    $lines = ['# exported list', ''];
    for ($i = 0; $i < 230; $i++) {
        $lines[] = 'apns_device_token,'.CampaignWorld::token($i)->value();
    }
    $lines[] = 'apns_device_token,'.CampaignWorld::token(3)->value();   // duplicate
    $lines[] = 'apns_device_token,not-a-token';                         // malformed value
    $lines[] = CampaignWorld::token(500)->value();                       // no kind: never guessed
    file_put_contents($path, implode("\n", $lines)."\n");

    $campaign = $this->w->dispatch(new DestinationFileAudience($path));
    $this->w->drain(1);
    file_put_contents($path, 'apns_device_token,'.CampaignWorld::token(900)->value()."\n", FILE_APPEND);
    $this->w->drain();
    @unlink($path);

    $row = CampaignWorld::row($campaign->id);
    expect(CampaignWorld::deliveries($campaign->id))->toBe(230)
        ->and($this->w->requestHistogram(230))->toBe([0 => 0, 1 => 230, 'many' => 0])
        ->and($this->w->requestsFor(500) + $this->w->requestsFor(900))->toBe(0)
        ->and((int) $row->invalid)->toBe(2)
        ->and((int) $row->planned)->toBe(230)
        ->and($this->w->campaigns()->reconcile('default', $campaign->id)->clean())->toBeTrue();
});

it('a host audience registered under an alias; if the class cannot be found at planning time the campaign fails, and resumes once it is back', function (): void {
    AudienceRegistry::register('every-other', EveryOtherToken::class);
    $campaign = $this->w->dispatch(new EveryOtherToken(6));
    expect(CampaignWorld::row($campaign->id)->audience_type)->toBe('every-other');

    AudienceRegistry::flush();   // a deploy lost the registration
    $this->w->drain();
    $failed = $this->w->campaigns()->find('default', $campaign->id);
    expect($failed?->status)->toBe(CampaignStatus::Failed)
        ->and($failed?->lastError)->toContain('Unknown campaign audience "every-other"')
        ->and(CampaignWorld::deliveries($campaign->id))->toBe(0);

    AudienceRegistry::register('every-other', EveryOtherToken::class);
    $this->w->campaigns()->resume('default', $campaign->id);
    $this->w->drain();
    AudienceRegistry::flush();

    expect(CampaignWorld::deliveries($campaign->id))->toBe(3)
        ->and([$this->w->requestsFor(0), $this->w->requestsFor(1), $this->w->requestsFor(2), $this->w->requestsFor(4)])->toBe([1, 0, 1, 1])
        ->and($this->w->campaigns()->find('default', $campaign->id)?->lastError)->toBeNull();
});

/** A host audience: the even token numbers below a limit, one at a time (so each slice holds one destination). */
final readonly class EveryOtherToken implements Audience
{
    public function __construct(public int $below) {}

    public function toArray(): array
    {
        return ['below' => $this->below];
    }

    public static function fromArray(array $parameters): static
    {
        return new self((int) $parameters['below']);
    }

    public function upperBound(AudienceContext $context): string
    {
        return KeysetCursor::encode('n', $this->below);
    }

    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice
    {
        $n = KeysetCursor::decode('n', $cursor);
        $upper = KeysetCursor::decode('n', $upperBound);
        if ($n >= $upper) {
            return new AudienceSlice([], $cursor, true);
        }

        return new AudienceSlice([CampaignWorld::token($n)], KeysetCursor::encode('n', $n + 2), $n + 2 >= $upper);
    }

    public function reach(): AudienceReach
    {
        return AudienceReach::Individual;
    }
}
