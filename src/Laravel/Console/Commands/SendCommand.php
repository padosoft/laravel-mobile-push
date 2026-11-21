<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Console\Support\ConsoleInputException;
use Padosoft\MobilePush\Laravel\Console\Support\Display;
use Padosoft\MobilePush\Laravel\Console\Support\MessageInput;
use Padosoft\MobilePush\Laravel\Console\Support\PlatformProposal;
use Padosoft\MobilePush\Laravel\Console\Support\SendExit;
use Padosoft\MobilePush\Laravel\Console\Support\StdinReader;
use Padosoft\MobilePush\Laravel\Console\Support\TokenInput;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Sends a push on the fly. The platform is NEVER inferred silently: name it with --platform or --connection; only an
 * interactive terminal may propose one (from the shape of the tokens) and it must be confirmed before anything is sent.
 *
 * Exit codes: 0 all accepted (or validated); 1 partial (some not accepted); 2 invalid input or configuration, nothing
 * sent; 3 simulated, nothing sent; 4 at least one unknown outcome.
 */
final class SendCommand extends PushCommand
{
    private const TABLE_LIMIT = 100;

    protected $signature = 'mobile-push:send
        {tokens?* : Device tokens (visible in the process list and shell history: prefer --stdin or --file for real sends)}
        {--platform= : ios or android: the platform of the raw tokens}
        {--connection= : The connection to send through}
        {--scope= : Tenant scope of the connection (default: mobile-push.scope)}
        {--file= : File with one token per line}
        {--stdin : Read tokens from standard input, one per line (recommended for real sends)}
        {--user= : Send to the registered devices of this user id (needs mobile-push.devices.enabled)}
        {--topic= : Send to this FCM topic (an Android connection; needs live.allow_topics outside production)}'.MessageInput::SIGNATURE.'
        {--queue : Queue the send instead of sending now}
        {--validate-only : FCM only: have Google validate the request without sending it}
        {--json : Print the result as JSON}';

    protected $description = 'Send a push on the fly (exit codes: 0 ok, 1 partial, 2 invalid input, 3 simulated, 4 unknown outcome)';

    public function handle(PushManager $manager, StdinReader $stdin): int
    {
        $secrets = [];

        try {
            return $this->dispatchSend($manager, $stdin, $secrets);
        } catch (ConsoleInputException|PushException $e) {
            return $this->failWith((new Redactor($secrets))->providerMessage($e->getMessage()), SendExit::INVALID, 'Nothing was sent.');
        }
    }

    /** @param  list<string>  $secrets  filled with the raw tokens so error texts can be scrubbed */
    private function dispatchSend(PushManager $manager, StdinReader $stdin, array &$secrets): int
    {
        $message = MessageInput::build($this);

        $useStdin = (bool) $this->option('stdin');
        $arguments = array_values(array_map('strval', (array) $this->argument('tokens')));
        $tokens = TokenInput::collect($arguments, $this->stringOption('file'), $useStdin ? $stdin->contents() : null);
        $secrets = count($tokens) <= 200 ? $tokens : [];

        $user = $this->stringOption('user');
        $topic = $this->stringOption('topic');
        $given = count(array_filter([$tokens !== [], $user !== null, $topic !== null]));
        if ($given === 0) {
            throw new ConsoleInputException('No destination given. Pass device tokens (arguments, --file or --stdin), --user=ID or --topic=NAME.');
        }
        if ($given > 1) {
            throw new ConsoleInputException('Choose one kind of destination per invocation: device tokens, --user or --topic. The package never mixes platforms in one send.');
        }

        $scope = $this->stringOption('scope') ?? $manager->currentScope();
        $warnings = [];
        if ($arguments !== []) {
            $warnings[] = 'Tokens given as arguments are visible in the process list and shell history. Use --stdin or --file for real sends.';
        }

        [$connection, $provider] = $this->target($manager, $scope, $tokens, $topic, $user, $useStdin);

        $pending = $manager->scope($scope)->connection($connection);
        if ($topic !== null) {
            $pending = $pending->toTopic($topic);
        } elseif ($user !== null) {
            $pending = $pending->to($this->user($user));
        } else {
            $platform = $provider === Provider::Apns ? Platform::Ios : Platform::Android;
            $pending = $pending->toTokens($platform, $tokens);
        }

        if ((bool) $this->option('validate-only')) {
            if ($provider !== Provider::Fcm) {
                throw new ConsoleInputException('--validate-only needs an FCM connection: APNs has no dry run. Use mobile-push:preview to see what would be sent.');
            }
            $pending = $pending->validateOnly();
        }

        if (! $this->wantsJson()) {
            foreach ($warnings as $warning) {
                $this->components->warn($warning);
            }
        }

        if ((bool) $this->option('queue')) {
            return $this->queued($pending->queue($message), $connection, $warnings);
        }

        return $this->report($pending->send($message), $connection, $warnings);
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: string, 1: Provider|null} connection name and provider (null when only the registry knows it)
     */
    private function target(PushManager $manager, string $scope, #[\SensitiveParameter] array $tokens, ?string $topic, ?string $user, bool $useStdin): array
    {
        $platformOption = $this->stringOption('platform');
        $platform = null;
        if ($platformOption !== null) {
            $platform = Platform::tryFrom(strtolower($platformOption))
                ?? throw new ConsoleInputException(sprintf('Unknown --platform "%s". Use ios or android.', $platformOption));
        }
        $named = $this->stringOption('connection');

        if ($user !== null) {
            if ($platform !== null) {
                throw new ConsoleInputException('--platform applies to raw tokens. To restrict a --user send to one platform, name its connection with --connection.');
            }
            if ($named === null) {
                // Several connections may be involved: the registry routes each device to its own connection.
                return [$this->onlyConnection($manager, $scope), null];
            }

            return [$named, $manager->providerOf($scope, $named)];
        }

        if ($topic !== null) {
            if ($platform === Platform::Ios) {
                throw new ConsoleInputException('A topic is an FCM destination: it cannot be sent with --platform=ios.');
            }
            $name = $named ?? $manager->routeFor($scope, Provider::Fcm);
            if ($manager->providerOf($scope, $name) !== Provider::Fcm) {
                throw new ConsoleInputException(sprintf('The connection "%s" is not an FCM connection: topics exist only on FCM.', $name));
            }

            return [$name, Provider::Fcm];
        }

        if ($named !== null) {
            $provider = $manager->providerOf($scope, $named);
            if ($platform !== null && $this->providerOf($platform) !== $provider) {
                throw new ConsoleInputException(sprintf('--platform=%s does not match the connection "%s", which is a %s connection.', $platform->value, $named, $provider->value));
            }

            return [$named, $provider];
        }

        if ($platform === null) {
            $platform = $this->proposed($tokens, $useStdin);
        }
        $provider = $this->providerOf($platform);

        return [$manager->routeFor($scope, $provider), $provider];
    }

    private function onlyConnection(PushManager $manager, string $scope): string
    {
        $names = $manager->routeNames($scope);
        if (count($names) !== 1) {
            throw new ConsoleInputException('A --user send needs the connection to use: name it with --connection (known: '.($names === [] ? 'none' : implode(', ', $names)).').');
        }

        return $names[0];
    }

    /** Asks a person to confirm a platform; never decides alone. */
    private function proposed(#[\SensitiveParameter] array $tokens, bool $useStdin): Platform
    {
        $remedy = 'The platform is never guessed. Pass --platform=ios or --platform=android (or --connection=NAME).';
        if (! $this->canAsk() || $useStdin) {
            throw new ConsoleInputException('Neither --platform nor --connection was given. '.$remedy);
        }
        $proposal = PlatformProposal::forTokens($tokens);
        if ($proposal === null) {
            throw new ConsoleInputException('The tokens do not allow a platform proposal (a mixed list never does). '.$remedy);
        }

        $label = $proposal === Platform::Ios ? 'iOS (APNs)' : 'Android (FCM)';
        $question = sprintf('The %d token(s) look like %s tokens. Send them as %s?', count($tokens), $label, $proposal->value);
        if (! $this->confirm($question, false)) {
            throw new ConsoleInputException('Platform not confirmed. '.$remedy);
        }

        return $proposal;
    }

    private function providerOf(Platform $platform): Provider
    {
        return $platform === Platform::Ios ? Provider::Apns : Provider::Fcm;
    }

    private function user(string $id): Model
    {
        $class = config('auth.providers.users.model');
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new ConsoleInputException('--user needs the user model: set auth.providers.users.model to an Eloquent model that uses HasPushDevices.');
        }
        $user = $class::query()->find($id);
        if (! $user instanceof Model) {
            throw new ConsoleInputException(sprintf('No %s with id "%s".', $class, $id));
        }

        return $user;
    }

    /** @param  list<string>  $warnings */
    private function queued(string $effectId, string $connection, array $warnings): int
    {
        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => SendExit::OK, 'queued' => true, 'connection' => $connection, 'effect_id' => $effectId, 'warnings' => $warnings]);

            return SendExit::OK;
        }
        $this->components->info(sprintf('Queued on connection "%s" (effect %s). Results are not known until a worker runs the job: check the logs or mobile-push:health.', $connection, $effectId));

        return SendExit::OK;
    }

    /** @param  list<string>  $warnings */
    private function report(SendReport $report, string $connection, array $warnings): int
    {
        $redactor = new Redactor;
        $counts = array_fill_keys(array_map(static fn (SendStatus $s): string => $s->value, SendStatus::cases()), 0);
        $rows = [];
        $json = [];
        $simulatedHint = null;
        $total = 0;

        foreach ($report->results() as $result) {
            $total++;
            $counts[$result->status->value]++;
            if ($result->status === SendStatus::Simulated) {
                $simulatedHint ??= $result->reason->hint;
            }
            if ($this->wantsJson()) {
                $json[] = $this->jsonRow($result, $redactor);
            } elseif ($total <= self::TABLE_LIMIT) {
                $rows[] = [
                    Display::destination($result->destination),
                    $result->status->value,
                    Display::reason($result->reason, $redactor),
                    Display::hint($result->reason, $redactor),
                    $result->providerMessageId ?? '',
                ];
            }
        }

        $exit = SendExit::fromCounts($counts);
        $summary = $this->summaryLine($total, $counts);

        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => $exit, 'connection' => $connection, 'summary' => $summary, 'counts' => array_filter($counts), 'results' => $json, 'warnings' => $warnings, 'simulated' => $simulatedHint !== null]);

            return $exit;
        }

        if ($simulatedHint !== null || $counts[SendStatus::Simulated->value] > 0) {
            $this->banner($simulatedHint);
        }
        if ($total === 0) {
            $this->components->error('There was nobody to send to: no destination resolved. Nothing was sent.');

            return SendExit::INVALID;
        }

        $this->table(['Destination', 'Status', 'Reason', 'Hint', 'Provider message id'], $rows);
        if ($total > self::TABLE_LIMIT) {
            $this->line(sprintf('... %d more row(s) not shown (use --json for every result).', $total - self::TABLE_LIMIT));
        }
        $this->line($summary.sprintf(' Exit code %d (%s).', $exit, SendExit::descriptions()[$exit]));

        return $exit;
    }

    private function banner(?string $hint): void
    {
        $this->newLine();
        $this->line('<bg=yellow;fg=black;options=bold> SIMULATED: NOTHING WAS SENT </>');
        $this->line('No request reached APNs or FCM: the effective mode is "simulate".');
        $this->line($hint ?? 'To send for real set MOBILE_PUSH_MODE=live (outside production also set live.allow=true on the connection and add the test device to live.only_to).');
        $this->newLine();
    }

    /** @param  array<string, int>  $counts */
    private function summaryLine(int $total, array $counts): string
    {
        $parts = [];
        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.str_replace('_', ' ', $status);
            }
        }

        return sprintf('%d destination(s): %s.', $total, $parts === [] ? 'none' : implode(', ', $parts));
    }

    /** @return array<string, mixed> */
    private function jsonRow(SendResult $result, Redactor $redactor): array
    {
        return [
            'destination' => Display::destination($result->destination),
            'kind' => $result->destination->kind()->value,
            'status' => $result->status->value,
            'reason_code' => $result->reason->code->value,
            'raw_reason' => $result->reason->rawReason === null ? null : $redactor->providerMessage($result->reason->rawReason),
            'hint' => $result->reason->hint === null ? null : $redactor->providerMessage($result->reason->hint),
            'provider_message_id' => $result->providerMessageId,
            'http_status' => $result->httpStatus,
            'attempts' => $result->attempts,
            'next_attempt_at' => $result->nextAttemptAt?->format(DATE_ATOM),
        ];
    }
}
