<?php

declare(strict_types=1);

require_once __DIR__.'/HealthKit.php';

use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\MobilePushHealthCheck;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\World;
use Spatie\Health\Enums\Status;

it('maps ok, warning and failed reports onto spatie/laravel-health results', function (): void {
    World::build($this->app);
    $ok = MobilePushHealthCheck::new()->run();
    expect($ok->status->equals(Status::ok()))->toBeTrue();

    World::build($this->app, ['mobile-push.mode' => 'simulate']);
    $warning = MobilePushHealthCheck::new()->run();
    expect($warning->status->equals(Status::warning()))->toBeTrue()
        ->and($warning->notificationMessage)->toContain('SIMULATE MODE IN PRODUCTION');

    World::build($this->app, ['mobile-push.mode' => 'nonsense']);
    $failed = MobilePushHealthCheck::new()->run();
    expect($failed->status->equals(Status::failed()))->toBeTrue();
    expect($this->app->make(HealthInspector::class))->toBeInstanceOf(HealthInspector::class);
});
