<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\CapturingHandler;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\RecordingTelemetry;

/** Canary markers. */
it('never lets a token, bearer or receipt marker reach a log line, a column, a span or a metric', function (SendStatus $status, ReasonCode $code) {
    $token = 'CANARY-DEVICE-TOKEN-'.bin2hex(random_bytes(12));
    $bearer = 'ya29.CANARY-BEARER-'.bin2hex(random_bytes(12));
    $receipt = 'rcpt_CANARY_RECEIPT_'.bin2hex(random_bytes(10));
    $short = 'sh0rtSecret';

    $telemetry = new RecordingTelemetry;
    app()->instance(TracerProviderInterface::class, $telemetry);
    app()->instance(MeterProviderInterface::class, $telemetry);
    F::migrate();
    F::configure('log,database,otel', ['payload' => 'full']);

    $manager = F::manager();
    $raw = "The registration token {$token} is not valid; Authorization: Bearer {$bearer}; marker={$receipt}";
    $result = F::result($token, $status, $raw, $code);

    $context = [
        'secrets' => [$bearer, $receipt, $short],
        'payload' => [
            'aps' => ['alert' => ['body' => "Open https://x.test/r?m={$receipt} now"]],
            'deviceToken' => $token,
            'note' => "echo {$short} and {$token}",
        ],
        'origin_id' => '42',
        'notification' => 'App\\Notifications\\OrderShipped',
        'effect_id' => 'eff_1',
        'campaign_id' => 'cmp_1',
        'push_type' => 'alert',
    ];
    $manager->recordResult(F::binding(), $result, $context);
    $manager->recordResult(F::binding(), $result, $context);
    $manager->flush();

    $rows = json_encode(DB::table('mobile_push_logs')->get()->all(), JSON_THROW_ON_ERROR);
    $surfaces = ['log' => CapturingHandler::dump(), 'database' => $rows, 'telemetry' => $telemetry->everything()];

    expect(count(CapturingHandler::$records))->toBe(2)
        ->and(count($telemetry->spans))->toBe(2)
        ->and(strlen($rows))->toBeGreaterThan(100);

    foreach ($surfaces as $surface => $text) {
        foreach (['token' => $token, 'bearer' => $bearer, 'receipt' => $receipt, 'short secret' => $short] as $what => $canary) {
            expect(str_contains($text, $canary))->toBeFalse("{$what} leaked into {$surface}");
            expect(str_contains($text, base64_encode($canary)))->toBeFalse("{$what} (base64) leaked into {$surface}");
        }
    }
})->with([
    'accepted' => [SendStatus::Accepted, ReasonCode::None],
    'rejected' => [SendStatus::Rejected, ReasonCode::DestinationRejected],
    'unknown' => [SendStatus::OutcomeUnknown, ReasonCode::TransportUnknown],
]);

it('shows a destination only as its keyed index and masked form', function () {
    F::migrate();
    F::configure('database');
    $token = F::token(7);
    F::manager()->recordResult(F::binding(), F::result($token));
    F::manager()->flush();

    $row = DB::table('mobile_push_logs')->first();

    expect($row->destination_index)->toMatch('/^v1:[0-9a-f]{64}$/')
        ->and($row->destination_masked)->toBe(TokenMasker::mask($token))
        ->and($row->destination_masked)->not->toBe($token)
        ->and($row->destination_kind)->toBe('fcm_token');
});
