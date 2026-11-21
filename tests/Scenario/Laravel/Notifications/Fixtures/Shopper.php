<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Padosoft\MobilePush\Laravel\Devices\HasPushDevices;

/** A user of the shop: owns push devices and has an email address. No table is needed. */
final class Shopper extends Model
{
    use HasPushDevices;
    use Notifiable;

    protected $table = 'shoppers_fixture';

    protected $guarded = [];

    public $timestamps = false;

    public static function withId(int $id, string $email = 'ada@example.test'): self
    {
        $shopper = new self(['id' => $id, 'email' => $email]);
        $shopper->exists = true;

        return $shopper;
    }
}
