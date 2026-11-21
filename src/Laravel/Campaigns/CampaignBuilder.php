<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;

/**
 * One message to a large audience, on the durable profile:
 *
 *   app(CampaignManager::class)->campaign(PushMessage::make('Black Friday', '-40% today'))
 *       ->name('black-friday-2026')->via('ios')->audience(DeviceAudience::all())
 *       ->rate(perMinute: 60_000)->dispatch();
 *
 * Immutable: every method returns a modified copy. dispatch() creates the campaign row and its ledger effect (the
 * message is stored encrypted there, never in the campaign row) and queues the first planner slice once the
 * campaigns connection commits. Traffic is always bulk: the shared rate limiter keeps the transactional share free.
 */
final class CampaignBuilder
{
    private ?string $name = null;

    private ?string $connection = null;

    private ?string $scope = null;

    private ?Audience $audience = null;

    private ?\DateTimeImmutable $expiresAt = null;

    private ?int $ratePerMinute;

    private ?string $queue = null;

    private ?string $queueConnection = null;

    public function __construct(
        private readonly CampaignManager $manager,
        private readonly Message $message,
        private readonly CampaignSettings $settings,
    ) {
        $this->ratePerMinute = $settings->defaultRatePerMinute;
    }

    /** A label for operators (not unique). */
    public function name(string $name): self
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A campaign name cannot be empty.');
        }

        return $this->with('name', $name);
    }

    /** The push connection (app binding) to send through. Default: mobile-push.default. */
    public function via(string $connection): self
    {
        return $this->with('connection', $connection);
    }

    /** The tenant (default: the current scope). */
    public function scope(string $scope): self
    {
        return $this->with('scope', $scope);
    }

    public function audience(Audience $audience): self
    {
        return $this->with('audience', $audience);
    }

    /** Logical expiry of the whole campaign: deliveries not sent by then end Expired, without a request. */
    public function expiresAt(\DateTimeInterface $at): self
    {
        return $this->with('expiresAt', \DateTimeImmutable::createFromInterface($at));
    }

    /** Planner pacing: at most this many destinations written per minute (null = unpaced). */
    public function rate(?int $perMinute): self
    {
        Pacing::assertRate($perMinute);

        return $this->with('ratePerMinute', $perMinute);
    }

    /** Queue name for the planner and relay jobs (default: mobile-push.queue.bulk_queue). */
    public function onQueue(?string $queue): self
    {
        return $this->with('queue', $queue);
    }

    /** Queue CONNECTION for the planner and relay jobs (not the push connection). */
    public function onQueueConnection(?string $connection): self
    {
        return $this->with('queueConnection', $connection);
    }

    /** Create the campaign and start planning it. */
    public function dispatch(): Campaign
    {
        $draft = $this->draft();

        return $this->manager->start($draft->scope, $draft->id);
    }

    /** Create the campaign without starting it (CampaignManager::start() later). */
    public function draft(): Campaign
    {
        $this->settings->assertUsable();
        $connection = $this->connection ?? self::defaultConnection();
        if ($this->audience === null) {
            throw new ConfigurationException('A campaign needs an audience: call ->audience().');
        }

        return $this->manager->create(
            $this->manager->dispatcher()->binding($connection, $this->scope),
            $this->message,
            $this->audience,
            $this->name ?? 'campaign',
            $this->expiresAt,
            $this->ratePerMinute,
            $this->queueConnection,
            $this->queue,
        );
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }

    private static function defaultConnection(): string
    {
        $default = config('mobile-push.default');
        if (! is_string($default) || $default === '') {
            throw new ConfigurationException('A campaign needs a push connection: call ->via() or set mobile-push.default.');
        }

        return $default;
    }
}
