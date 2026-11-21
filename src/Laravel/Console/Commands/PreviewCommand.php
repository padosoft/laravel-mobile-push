<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Laravel\Console\Support\ConsoleInputException;
use Padosoft\MobilePush\Laravel\Console\Support\LocalCompiler;
use Padosoft\MobilePush\Laravel\Console\Support\MessageInput;

/**
 * Compiles a message locally and prints what would go on the wire. Nothing is sent and nothing is validated remotely.
 *
 * Exit codes: 0 compiled; 2 invalid input, invalid message, or payload over the limit.
 */
final class PreviewCommand extends PushCommand
{
    protected $signature = 'mobile-push:preview
        {--platform= : ios or android (needed without --connection)}
        {--connection= : Compile for this connection (its app id and environment)}
        {--scope= : Tenant scope of the connection}'.MessageInput::SIGNATURE.'
        {--json : Print the result as JSON}';

    protected $description = 'Show the headers and JSON a message compiles to, locally (nothing is sent or validated remotely)';

    public function handle(LocalCompiler $compiler): int
    {
        try {
            $message = MessageInput::build($this);
            $platformOption = $this->stringOption('platform');
            $platform = $platformOption === null ? null : (Platform::tryFrom(strtolower($platformOption))
                ?? throw new ConsoleInputException(sprintf('Unknown --platform "%s". Use ios or android.', $platformOption)));
            $preview = $compiler->compile($message, $platform, $this->stringOption('connection'), $this->stringOption('scope'));
        } catch (ConsoleInputException|PushException $e) {
            return $this->failWith($e->getMessage(), 2, 'Nothing was compiled and nothing was sent.');
        }

        $compiled = $preview->compiled;
        $decoded = json_decode($compiled->body, true);
        $pretty = $decoded === null ? $compiled->body : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $note = 'Local preview only: nothing was sent and nothing was validated remotely (no credential, token or topic was checked). APNs has no dry run; FCM can validate remotely with mobile-push:send --validate-only.';

        if ($this->wantsJson()) {
            $this->emitJson([
                'exit_code' => 0,
                'provider' => $preview->provider,
                'binding' => $preview->bindingLabel,
                'destination' => $preview->destinationLabel,
                'headers' => $compiled->headers,
                'payload' => $decoded,
                'payload_bytes' => $compiled->payloadBytes,
                'limit_bytes' => $compiled->limitBytes,
                'warnings' => $compiled->warnings,
                'requires_app_code' => $preview->appCodeNotes,
                'unsupported' => $preview->unsupported,
                'note' => $note,
            ]);

            return 0;
        }

        $this->components->info('Preview for '.strtoupper($preview->provider).' using '.$preview->bindingLabel);
        $this->line($note);
        $this->newLine();
        $this->line('<options=bold>Destination</>  '.$preview->destinationLabel);
        $this->line('<options=bold>Headers</> (the authorization header is added at send time and never shown)');
        foreach ($compiled->headers as $name => $value) {
            $this->line(sprintf('  %s: %s', $name, $value));
        }
        $this->newLine();
        $this->line('<options=bold>Payload</>');
        $this->line($pretty);
        $this->newLine();
        $this->line(sprintf('<options=bold>Size</> %d of %d bytes (%d%%)', $compiled->payloadBytes, $compiled->limitBytes, $compiled->limitBytes > 0 ? (int) round($compiled->payloadBytes * 100 / $compiled->limitBytes) : 0));

        $this->list('Warnings', $compiled->warnings);
        $this->list('Requires app code (the push alone is not enough)', $preview->appCodeNotes);
        $this->list('Not supported on this platform', $preview->unsupported);

        return 0;
    }

    /** @param  list<string>  $items */
    private function list(string $title, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->newLine();
        $this->line('<options=bold>'.$title.'</>');
        foreach ($items as $item) {
            $this->line('  - '.$item);
        }
    }
}
