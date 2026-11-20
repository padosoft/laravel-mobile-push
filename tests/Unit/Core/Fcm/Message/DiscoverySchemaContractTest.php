<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidPriority;
use Padosoft\MobilePush\Core\Fcm\Message\FcmApnsConfig;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Fcm\Message\LightSettings;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationPriority;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationProxy;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationVisibility;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * The oracle that keeps the builder honest: everything the compiler can emit must exist in the FCM HTTP v1
 * Discovery document snapshot (tests/Fixtures/fcm/discovery-v1.json), enum values included, and everything
 * the Discovery document defines under SendMessageRequest must be reachable from the builder (except the
 * explicit exclusions below). Wire names are snake_case (protobuf); the Discovery uses camelCase, same names.
 */

const NOT_MODELLED = [
    'Message.name' => 'output only',
    'Message.webpush' => 'Web Push is out of scope for v1 of the package',
];

/** @return array<string, mixed> */
function discovery(): array
{
    static $doc = null;

    return $doc ??= json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/fcm/discovery-v1.json'), true, 512, JSON_THROW_ON_ERROR);
}

function camel(string $snake): string
{
    return lcfirst(str_replace('_', '', ucwords($snake, '_')));
}

/**
 * Walk a decoded JSON value against a schema, asserting names, types and enums. Returns every "Schema.property" seen.
 *
 * @param  array<string, true>  $seen
 */
function walkSchema(string $schema, mixed $value, string $path, array &$seen): void
{
    $schemas = discovery()['schemas'];
    expect(array_key_exists($schema, $schemas))->toBeTrue("unknown schema {$schema}");
    expect(is_array($value))->toBeTrue("{$path} must be a JSON object");

    foreach ($value as $key => $child) {
        $name = camel((string) $key);
        $properties = $schemas[$schema]['properties'] ?? [];
        expect(array_key_exists($name, $properties))->toBeTrue("{$path}.{$key} (as {$schema}.{$name}) is not in the Discovery schema");
        $seen["{$schema}.{$name}"] = true;
        checkValue($properties[$name], $child, "{$path}.{$key}", $seen);
    }
}

/**
 * @param  array<string, mixed>  $definition
 * @param  array<string, true>  $seen
 */
function checkValue(array $definition, mixed $value, string $path, array &$seen): void
{
    if (isset($definition['$ref'])) {
        walkSchema($definition['$ref'], $value, $path, $seen);

        return;
    }

    $type = $definition['type'] ?? 'any';
    if (isset($definition['enum'])) {
        expect(in_array($value, $definition['enum'], true))->toBeTrue("{$path} = ".json_encode($value).' is not one of the schema enum values');
    }

    switch ($type) {
        case 'string':
            expect(is_string($value))->toBeTrue("{$path} must be a string");
            break;
        case 'boolean':
            expect(is_bool($value))->toBeTrue("{$path} must be a boolean");
            break;
        case 'integer':
            expect(is_int($value))->toBeTrue("{$path} must be an integer");
            break;
        case 'number':
            expect(is_int($value) || is_float($value))->toBeTrue("{$path} must be a number");
            break;
        case 'array':
            expect(is_array($value))->toBeTrue("{$path} must be an array");
            foreach ($value as $i => $item) {
                checkValue($definition['items'], $item, "{$path}[{$i}]", $seen);
            }
            break;
        case 'object':
            expect(is_array($value))->toBeTrue("{$path} must be an object");
            $additional = $definition['additionalProperties'] ?? [];
            if (($additional['type'] ?? null) === 'string') {
                foreach ($value as $k => $item) {
                    expect(is_string($item))->toBeTrue("{$path}.{$k} must be a string (map<string,string>)");
                }
            }
            break;
    }
}

/** Every Schema.property reachable from SendMessageRequest, minus what we explicitly do not model. */
function reachableProperties(): array
{
    $schemas = discovery()['schemas'];
    $todo = ['SendMessageRequest'];
    $done = [];
    $props = [];

    while ($todo !== []) {
        $schema = array_pop($todo);
        if (isset($done[$schema])) {
            continue;
        }
        $done[$schema] = true;
        foreach ($schemas[$schema]['properties'] ?? [] as $name => $definition) {
            if (isset(NOT_MODELLED["{$schema}.{$name}"])) {
                continue;
            }
            $props[] = "{$schema}.{$name}";
            $ref = $definition['$ref'] ?? $definition['items']['$ref'] ?? null;
            $ref !== null && $todo[] = $ref;
        }
    }

    sort($props);

    return $props;
}

function kitchenSinkV1(): FcmMessage
{
    return FcmMessage::make('Title', 'Body')
        ->image('https://cdn.example.test/n.png')
        ->data(['a' => 'b', 'n' => 1])
        ->analyticsLabel('label')
        ->android(fn (AndroidConfig $a) => $a
            ->collapseKey('ck')->priority(AndroidPriority::High)->ttl(3600)->restrictedPackageName('com.example.app')
            ->data(['x' => 'y'])->analyticsLabel('android-label')->directBootOk()->bandwidthConstrainedOk()->restrictedSatelliteOk()
            ->notification(fn (AndroidNotification $n) => $n
                ->title('t')->body('b')->icon('ic')->color('#112233')->sound('default')->tag('tg')->clickAction('OPEN')
                ->bodyLocKey('bk')->bodyLocArgs(['1'])->titleLocKey('tk')->titleLocArgs(['2'])->channelId('ch')->ticker('tick')
                ->sticky()->eventTime('2026-11-20T10:00:00Z')->localOnly()->notificationPriority(NotificationPriority::High)
                ->defaultSound()->defaultVibrateTimings()->vibrateTimings([0, '0.5s'])->defaultLightSettings()
                ->lightSettings(LightSettings::of(1.0, 0.5, 0.25, 1, 2, 1.0))->visibility(NotificationVisibility::Secret)
                ->notificationCount(4)->image('https://cdn.example.test/a.png')->proxy(NotificationProxy::IfPriorityLowered)
                ->bypassProxyNotification()))
        ->apns(fn (FcmApnsConfig $a) => $a
            ->header('apns-priority', 10)->payload(['aps' => ['sound' => 'default'], 'custom' => ['x' => 1]])
            ->liveActivityToken('live-activity-token-test')->image('https://cdn.example.test/i.png')->analyticsLabel('apns-label'));
}

function kitchenSinkV2(): FcmMessage
{
    return FcmMessage::make('Title', 'Body')
        ->androidV2(fn (AndroidConfigV2 $a) => $a
            ->collapseKey('ck')->ttl('60s')->restrictedPackageName('com.example.app')->data(['x' => 'y'])
            ->mutableContent()->useAsV1DataMessage()->backgroundSync()->analyticsLabel('l')
            ->directBootOk()->bandwidthConstrainedOk()->restrictedSatelliteOk()
            ->notification(fn (AndroidNotification $n) => $n
                ->title('t')->body('b')->icon('ic')->color('#112233')->sound('default')->tag('tg')->clickAction('OPEN')
                ->bodyLocKey('bk')->bodyLocArgs(['1'])->titleLocKey('tk')->titleLocArgs(['2'])->channelId('ch')->ticker('tick')
                ->sticky()->eventTime('2026-11-20T10:00:00Z')->localOnly()->notificationPriority(NotificationPriority::Low)
                ->defaultSound()->defaultVibrateTimings()->vibrateTimings([0, '0.5s'])->defaultLightSettings()
                ->lightSettings(LightSettings::of(0.0, 0.0, 1.0, 0.5, 0.5))->visibility(NotificationVisibility::Private)
                ->notificationCount(4)->image('https://cdn.example.test/a.png')->id(9)));
}

it('emits only property names and enum values that exist in the Discovery schema, and covers all of them', function () {
    $seen = [];

    $runs = [
        [kitchenSinkV1(), FcmKit::token(), true],
        [kitchenSinkV2(), FcmKit::fid(), false],
        [FcmMessage::make('t', 'b'), FcmKit::topic(), false],
        [FcmMessage::make('t', 'b'), FcmKit::condition(), false],
    ];

    foreach ($runs as [$message, $destination, $validateOnly]) {
        $compiled = FcmKit::compile($message, $destination, FcmKit::context(validateOnly: $validateOnly));
        walkSchema('SendMessageRequest', FcmKit::decode($compiled), 'request', $seen);
    }

    $seen = array_keys($seen);
    sort($seen);
    $expected = reachableProperties();

    expect(array_values(array_diff($expected, $seen)))->toBe([], 'schema properties the builder cannot emit')
        ->and(array_values(array_diff($seen, $expected)))->toBe([]);
});

it('also stays inside the schema for everything the unified PushMessage maps to', function () {
    $push = PushMessage::make('t', 'b')->image('https://x.test/i.png')->data(['a' => true])
        ->badge(2)->sound('s')->actionCategory('c')->group('g')->collapse('k')->deepLink('app://x');
    $seen = [];

    $compiled = FcmKit::compile($push, context: FcmKit::context('2026-11-20T10:00:00Z', '2026-11-20T11:00:00Z', true, 'marker'));
    walkSchema('SendMessageRequest', FcmKit::decode($compiled), 'request', $seen);

    expect($seen)->toHaveKey('AndroidNotification.notificationCount')->and($seen)->toHaveKey('AndroidConfig.ttl');
});

it('has PHP enums whose cases are exactly the Discovery enum values', function (string $enum, string $schema, string $property) {
    $values = array_map(fn (UnitEnum $case) => $case->value, $enum::cases());
    $schemaValues = discovery()['schemas'][$schema]['properties'][$property]['enum'];

    sort($values);
    sort($schemaValues);

    expect($values)->toBe($schemaValues);
})->with([
    'priority' => [AndroidPriority::class, 'AndroidConfig', 'priority'],
    'visibility' => [NotificationVisibility::class, 'AndroidNotification', 'visibility'],
    'v2 visibility' => [NotificationVisibility::class, 'AndroidNotificationV2', 'visibility'],
    'notification priority' => [NotificationPriority::class, 'AndroidNotification', 'notificationPriority'],
    'v2 notification priority' => [NotificationPriority::class, 'AndroidNotificationV2', 'notificationPriority'],
    'proxy' => [NotificationProxy::class, 'AndroidNotification', 'proxy'],
]);

it('keeps the snapshot honest: the meta file matches the Discovery file', function () {
    $dir = __DIR__.'/../../../../Fixtures/fcm';
    $meta = json_decode((string) file_get_contents($dir.'/discovery-v1.meta.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($meta['sha256'])->toBe(hash_file('sha256', $dir.'/discovery-v1.json'))
        ->and($meta['revision'])->toBe(discovery()['revision'])
        ->and(discovery()['schemas']['Message']['properties'])->toHaveKeys(['token', 'fid', 'topic', 'condition', 'android', 'androidV2']);
});
