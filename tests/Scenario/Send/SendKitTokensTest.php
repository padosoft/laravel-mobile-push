<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

it('gives every integer its own APNs test token (10 and 170 used to collide)', function () {
    $values = [];
    foreach ([...range(0, 4096), 10, 170, 2730, PHP_INT_MAX] as $i) {
        $values[$i] = SendKit::apnsToken($i)->value();
    }

    expect(SendKit::apnsToken(10)->value())->not->toBe(SendKit::apnsToken(170)->value())
        ->and(array_unique($values))->toHaveCount(count($values))
        ->and(strlen($values[PHP_INT_MAX]))->toBe(64);
});
