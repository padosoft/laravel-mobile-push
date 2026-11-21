<?php
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
require_once __DIR__.'/../Queue/QueueWorld.php';
it('bench', function (int $n) {
    QueueWorld::boot('durable', [], devices: true);
    $ids = [];
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $id = strtoupper(bin2hex(random_bytes(13))); $ids[] = $id;
        $rows[] = ['id'=>$id,'effect_id'=>'E','scope'=>'default','kind'=>'apns','logical_key'=>'k'.$i,'state'=>'pending','next_attempt_at'=>1000,'created_at'=>1,'updated_at'=>1];
        if (count($rows) === 500) { DB::table('mobile_push_deliveries')->insert($rows); $rows = []; }
    }
    $t = hrtime(true);
    for ($k = 0; $k < 5000; $k++) {
        DB::table('mobile_push_deliveries')->where('id', $ids[$k])->where('version', 0)->update(['state' => 'accepted', 'updated_at' => 5 + $k, 'version' => 1]);
    }
    $a = (hrtime(true) - $t) / 1e6 / 5000;
    $t = hrtime(true);
    $pdo = DB::connection()->getPdo();
    for ($k = 5000; $k < 10000; $k++) {
        $s = $pdo->prepare('update "mobile_push_deliveries" set "state" = ?, "updated_at" = ?, "version" = ? where "id" = ? and "version" = ?');
        $s->execute(['accepted', 5 + $k, 1, $ids[$k], 0]);
    }
    $b = (hrtime(true) - $t) / 1e6 / 5000;
    fwrite(STDERR, sprintf("\n%d rows: laravel %.3f ms, raw prepare %.3f ms, txlevel %d, journal %s\n", $n, $a, $b, DB::transactionLevel(), json_encode(DB::select('pragma journal_mode'))));
    expect(true)->toBeTrue();
})->with([25000, 50000, 100000]);
