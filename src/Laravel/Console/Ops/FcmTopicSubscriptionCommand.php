<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicApiException;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicBatchResult;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Subscribe or unsubscribe FCM installations to a topic, or list an installation's subscriptions. One installation
 * per API call (FCM has no bulk operation), multiplexed; one result row per installation.
 *
 * Installations: --token=<registration token> (repeatable), --fid=<installation id> (repeatable), and --stdin, which
 * reads one per line: a bare line is a registration token, a line `fid=<id>` is an FID (a token is never guessed from
 * its shape). Tokens given as options end up in your shell history: prefer --stdin for real tokens.
 *
 * Exit code: 0 every installation succeeded (or the mode is simulation: nothing was sent), 1 at least one failed
 * (or was blocked by safe mode) or the usage was wrong.
 */
final class FcmTopicSubscriptionCommand extends OpsCommand
{
    protected $signature = 'mobile-push:fcm:topic-subscription
        {action : subscribe, unsubscribe or list}
        {topic? : The topic (subscribe and unsubscribe)}
        {--connection= : The FCM connection to use (default: the only FCM connection)}
        {--token=* : FCM registration token (repeatable)}
        {--fid=* : FCM installation id (repeatable)}
        {--stdin : Read installations from standard input, one per line (token, or fid=<id>)}
        {--scope= : Scope (tenant); default is the current scope}
        {--json : Print the result as JSON}';

    protected $description = 'Subscribe, unsubscribe or list FCM topic subscriptions of installations';

    public function handle(PushManager $manager): int
    {
        $action = $this->stringArgument('action');
        if (! in_array($action, ['subscribe', 'unsubscribe', 'list'], true)) {
            throw new UsageError('The action must be subscribe, unsubscribe or list.');
        }
        $topicName = $this->argument('topic');
        $topic = null;
        if ($action !== 'list') {
            if (! is_string($topicName) || $topicName === '') {
                throw new UsageError('Say the topic: mobile-push:fcm:topic-subscription '.$action.' <topic> ...');
            }
            try {
                $topic = FcmTopic::of($topicName);
            } catch (PushException $e) {
                throw new UsageError('The topic is not valid: '.$e->getMessage());
            }
        }

        $installations = $this->installations();
        if ($installations === []) {
            throw new UsageError('No installation given: use --token, --fid or --stdin.');
        }

        [$client, $binding] = $this->client($manager);

        if ($action === 'list') {
            return $this->list($client, $installations, $binding);
        }

        /** @var FcmTopic $topic */
        $results = $action === 'subscribe'
            ? $client->subscribeMany($installations, $topic)
            : $client->unsubscribeMany($installations, $topic);

        return $this->report($action, $topic, $binding, $installations, $results);
    }

    /**
     * @param  list<FcmToken|FcmFid>  $installations
     * @param  list<TopicBatchResult>  $results
     */
    private function report(string $action, FcmTopic $topic, BindingRef $binding, array $installations, array $results): int
    {
        $rows = [];
        $failed = 0;
        $simulated = 0;
        foreach ($installations as $i => $installation) {
            $result = $results[$i] ?? null;
            $code = $result?->reason->code;
            $isSimulated = $code === ReasonCode::Simulated;
            $status = match (true) {
                $result === null => 'no result',
                $result->ok && $result->alreadyInState => $action === 'subscribe' ? 'already subscribed' : 'done',
                $result->ok => $action === 'subscribe' ? 'subscribed' : 'unsubscribed',
                $isSimulated => 'simulated',
                default => 'failed',
            };
            $failed += ($result === null || (! $result->ok && ! $isSimulated)) ? 1 : 0;
            $simulated += $isSimulated ? 1 : 0;
            $rows[] = [
                'n' => $i + 1,
                'installation' => self::label($installation),
                'result' => $status,
                'http' => $result === null || $result->status === 0 ? null : $result->status,
                'reason' => $result === null || $result->ok ? null : $result->reason->code->value.($result->reason->rawReason === null ? '' : ' ('.$result->reason->rawReason.')'),
                'hint' => $result === null || $result->ok ? null : $result->reason->hint,
            ];
        }

        if ($this->wantsJson()) {
            $this->emitJson(['action' => $action, 'topic' => $topic->value(), 'connection' => $binding->name, 'failed' => $failed, 'simulated' => $simulated, 'installations' => $rows]);
        } else {
            $this->table(['#', 'Installation', 'Result', 'HTTP', 'Reason'], array_map(
                static fn (array $r): array => [$r['n'], $r['installation'], $r['result'], $r['http'] ?? '-', $r['reason'] ?? '-'],
                $rows,
            ));
            if ($simulated > 0) {
                $this->components->warn('Simulation mode: no request was sent for '.$simulated.' installation(s). Set MOBILE_PUSH_MODE=live (and the binding\'s live.allow_topics outside production) to subscribe for real.');
            }
            if ($failed > 0) {
                $this->components->error($failed.' of '.count($installations).' installation(s) failed.');
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<FcmToken|FcmFid>  $installations */
    private function list(TopicSubscriptionClient $client, array $installations, BindingRef $binding): int
    {
        $out = [];
        $failed = 0;
        foreach ($installations as $i => $installation) {
            $row = ['n' => $i + 1, 'installation' => self::label($installation), 'topics' => [], 'error' => null];
            try {
                foreach ($client->list($installation) as $subscription) {
                    $row['topics'][] = ['topic' => $subscription->topicName, 'created' => $subscription->createTime?->format(DATE_ATOM)];
                }
            } catch (TopicApiException $e) {
                $failed += $e->reason->code === ReasonCode::Simulated ? 0 : 1;
                $row['error'] = ($e->reason->code->value).($e->status > 0 ? ' (HTTP '.$e->status.')' : '');
            }
            $out[] = $row;
        }

        if ($this->wantsJson()) {
            $this->emitJson(['action' => 'list', 'connection' => $binding->name, 'failed' => $failed, 'installations' => $out]);
        } else {
            $table = [];
            foreach ($out as $row) {
                if ($row['error'] !== null) {
                    $table[] = [$row['n'], $row['installation'], '-', 'failed: '.$row['error']];

                    continue;
                }
                if ($row['topics'] === []) {
                    $table[] = [$row['n'], $row['installation'], '(none)', ''];

                    continue;
                }
                foreach ($row['topics'] as $t) {
                    $table[] = [$row['n'], $row['installation'], $t['topic'], $t['created'] ?? ''];
                }
            }
            $this->table(['#', 'Installation', 'Topic', 'Created'], $table);
            if ($failed > 0) {
                $this->components->error($failed.' installation(s) could not be listed.');
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: TopicSubscriptionClient, 1: BindingRef} */
    private function client(PushManager $manager): array
    {
        $scope = $this->currentScope();
        try {
            $name = $this->stringOption('connection') ?? $manager->routeFor($scope, Provider::Fcm);
            if ($manager->providerOf($scope, $name) !== Provider::Fcm) {
                throw new UsageError('The connection "'.$name.'" is not an FCM connection.');
            }

            return [$manager->topicSubscriptions($name, $scope), $manager->binding($name, $scope)];
        } catch (PushException $e) {
            throw new UsageError($e->getMessage());
        }
    }

    /** @return list<FcmToken|FcmFid> */
    private function installations(): array
    {
        $out = [];
        try {
            foreach ($this->listOption('token') as $token) {
                $out[] = FcmToken::of($token);
            }
            foreach ($this->listOption('fid') as $fid) {
                $out[] = FcmFid::of($fid);
            }
            if ($this->option('stdin')) {
                foreach (preg_split('/\R/', (string) stream_get_contents(STDIN)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $out[] = str_starts_with($line, 'fid=') ? FcmFid::of(substr($line, 4)) : FcmToken::of($line);
                }
            }
        } catch (\InvalidArgumentException|PushException) {
            // Never echo the offending value: it is a token.
            throw new UsageError('One of the installations given is not valid (empty, too long or with forbidden characters). Check the value; it is not repeated here on purpose.');
        }

        return $out;
    }

    private static function label(FcmToken|FcmFid $installation): string
    {
        return ($installation instanceof FcmFid ? 'fid ' : 'token ').TokenMasker::mask($installation->value());
    }
}
