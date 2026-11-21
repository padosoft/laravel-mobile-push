<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Laravel\Console\Support\ConsoleInputException;
use Padosoft\MobilePush\Laravel\Console\Support\LocalCompiler;
use Padosoft\MobilePush\Laravel\Console\Support\MessageInput;
use Padosoft\MobilePush\Laravel\Console\Support\XcrunRunner;

/**
 * Builds the `.apns` payload file the iOS Simulator accepts (with the "Simulator Target Bundle" key), writes it to
 * --export and, on macOS with xcrun, pushes it to the simulator. It checks how a notification RENDERS in the simulator:
 * it does not exercise the APNs infrastructure or your credentials.
 *
 * Exit codes: 0 done; 2 invalid input, nothing written or pushed; 5 the xcrun push failed.
 */
final class SimulatorCommand extends PushCommand
{
    protected $signature = 'mobile-push:simulator
        {--bundle= : Bundle id of the app in the simulator (default: the bundle of the only APNs connection)}
        {--device=booted : Simulator device id, or "booted"}
        {--export= : Write the .apns payload file to this path}'.MessageInput::SIGNATURE;

    protected $description = 'Build an APNs payload file for the iOS Simulator (checks rendering only, not APNs or credentials)';

    public function handle(LocalCompiler $compiler, XcrunRunner $xcrun): int
    {
        try {
            $message = MessageInput::build($this);
            $bundle = $this->stringOption('bundle') ?? $this->configuredBundle();
            $preview = $compiler->compile($message, Platform::Ios, null, null, $bundle);
        } catch (ConsoleInputException|PushException $e) {
            return $this->failWith($e->getMessage(), 2, 'Nothing was written or pushed.');
        }

        $payload = json_decode($preview->compiled->body, true);
        if (! is_array($payload)) {
            return $this->failWith('The compiled payload is not a JSON object.', 2);
        }
        $file = (string) json_encode(['Simulator Target Bundle' => $bundle, ...$payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $export = $this->stringOption('export');
        $canPush = $xcrun->available();
        if ($export === null && ! $canPush) {
            return $this->failWith('Nothing to do with the payload: --export was not given and xcrun is not available here (it exists only on macOS with Xcode).', 2, 'Pass --export=path/to/payload.apns, then drag the file onto the simulator or run xcrun simctl push booted payload.apns on a Mac.');
        }

        $this->components->info(sprintf('Payload for bundle %s (%d bytes of %d).', $bundle, $preview->compiled->payloadBytes, $preview->compiled->limitBytes));

        if ($export !== null) {
            $directory = dirname($export);
            if (! is_dir($directory) || ! is_writable($directory) || file_put_contents($export, $file) === false) {
                return $this->failWith(sprintf('Cannot write "%s": the directory does not exist or is not writable.', $export), 2, 'Create the directory or choose another --export path.');
            }
            $this->line('Exported to '.$export);
        }

        $exit = 0;
        if ($canPush) {
            $target = $export;
            $temporary = null;
            if ($target === null) {
                $target = $temporary = tempnam(sys_get_temp_dir(), 'mpush').'.apns';
                file_put_contents($target, $file);
            }
            [$ok, $output] = $xcrun->push($this->stringOption('device') ?? 'booted', $target);
            if ($temporary !== null) {
                @unlink($temporary);
            }
            if ($ok) {
                $this->line('Pushed to the simulator ('.($this->stringOption('device') ?? 'booted').').');
            } else {
                $this->components->error('xcrun simctl push failed: '.$output);
                $exit = 5;
            }
        } else {
            $this->line('xcrun is not available here (macOS with Xcode only): the file was only exported. On a Mac run: xcrun simctl push booted '.$export);
        }

        $this->newLine();
        $this->line('This checks how the notification RENDERS in the simulator. It does not test APNs, your credentials, the device token or the environment.');

        return $exit;
    }

    private function configuredBundle(): string
    {
        $connections = config('mobile-push.connections', []);
        $bundles = [];
        foreach (is_array($connections) ? $connections : [] as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'apns' && is_string($connection['bundle_id'] ?? null) && $connection['bundle_id'] !== '') {
                $bundles[$connection['bundle_id']] = true;
            }
        }
        if (count($bundles) !== 1) {
            throw new ConsoleInputException('Pass --bundle=com.example.app: '.($bundles === [] ? 'no APNs connection with a bundle id is configured.' : 'several APNs bundles are configured and the package never guesses which one.'));
        }

        return (string) array_key_first($bundles);
    }
}
