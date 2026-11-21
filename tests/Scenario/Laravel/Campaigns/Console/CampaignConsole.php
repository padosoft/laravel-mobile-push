<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\Console;

use Illuminate\Support\Facades\Artisan;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Campaigns\Console\ConsoleCommands;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../CampaignWorld.php';

/** The campaigns world plus the console: commands registered, every output checked for clear tokens. */
final class CampaignConsole
{
    public const DEVICES = 200;

    public function __construct(public readonly CampaignWorld $world) {}

    /** @param  array<string, mixed>  $config */
    public static function install(array $config = []): self
    {
        $console = new self(CampaignWorld::boot($config));
        foreach (ConsoleCommands::all() as $command) {
            Artisan::registerCommand(app($command));
        }

        return $console;
    }

    /**
     * Tokens 0-9: the response is lost after the request was written (unknown outcome). Tokens 10-14: refused.
     */
    public function lossyTransport(): void
    {
        $this->world->answer(static function (Request $r) {
            $n = (int) hexdec(substr(SendKit::tokenOf($r), -12)) - 1;

            return match (true) {
                $n < 10 => new TransportFailure(TransportEvidence::Unknown, 56, 'connection reset after write', true),
                $n < 15 => SendKit::apnsReason(400, 'BadDeviceToken'),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: int, 1: string} exit code and output
     */
    public function cli(string $command, array $params = []): array
    {
        $exit = Artisan::call($command, $params);
        $output = Artisan::output();

        // The canary: no clear token this world ever seeded may appear in any output.
        for ($i = 0; $i < self::DEVICES; $i++) {
            expect($output)->not->toContain(CampaignWorld::token($i)->value());
        }

        return [$exit, $output];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function json(string $command, array $params = []): array
    {
        [, $output] = $this->cli($command, $params + ['--json' => true]);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }
}
