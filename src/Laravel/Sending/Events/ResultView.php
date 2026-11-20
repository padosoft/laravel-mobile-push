<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

/**
 * A SendResult with its destination replaced by a DestinationView: everything a listener (or a queued listener,
 * after serialization) may see. The raw token is not part of it.
 */
final readonly class ResultView
{
    /**
     * @param  array<string, string>  $meta
     */
    public function __construct(
        public DestinationView $destination,
        public SendStatus $status,
        public Reason $reason,
        public int $attempts,
        public ?string $providerMessageId,
        public ?int $httpStatus,
        public ?\DateTimeImmutable $nextAttemptAt,
        public DestinationAction $destinationAction,
        public ?int $invalidatedAtMs,
        public ?string $deliveryId,
        public array $meta,
    ) {}

    public static function of(SendResult $result, TokenIndex $index, string $scope): self
    {
        return new self(
            DestinationView::of($result->destination, $index, $scope),
            $result->status,
            $result->reason,
            $result->attempts,
            $result->providerMessageId,
            $result->httpStatus,
            $result->nextAttemptAt,
            $result->destinationAction,
            $result->invalidatedAtMs,
            $result->deliveryId,
            $result->meta,
        );
    }
}
