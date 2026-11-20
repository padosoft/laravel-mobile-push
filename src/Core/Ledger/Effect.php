<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Policy\OperationKind;

/** One logical thing to send, as stored. The message snapshot is read through EffectStore::message(). */
final readonly class Effect
{
    public function __construct(
        public string $id,
        public string $scope,
        public BindingRef $binding,
        public string $effectKey,
        public string $fingerprint,
        public OperationKind $operation,
        public bool $replaceable,
        public ?\DateTimeImmutable $expiresAt,
        public ?string $policy,
        public TrafficClass $trafficClass,
        public ?EffectOrigin $origin,
        public ?string $campaignId,
        public \DateTimeImmutable $dedupeUntil,
        public \DateTimeImmutable $createdAt,
        /** False once the snapshot was erased (forget) */
        public bool $hasMessage,
    ) {}

    /** @param array<string, mixed>|object $row a stored effects row */
    public static function fromRow(array|object $row): self
    {
        $row = Row::of($row);
        /** @var array{scope: string, name: string, provider: string, app_id: string, environment: string, generation: string} $binding */
        $binding = Row::json($row, 'binding') ?? [];
        $originType = Row::strOrNull($row, 'origin_type');
        $originId = Row::strOrNull($row, 'origin_id');
        $notification = Row::strOrNull($row, 'notification_class');

        return new self(
            Row::str($row, 'id'),
            Row::str($row, 'scope'),
            BindingRef::fromArray($binding),
            Row::str($row, 'effect_key'),
            Row::str($row, 'fingerprint'),
            OperationKind::from(Row::str($row, 'operation')),
            Row::bool($row, 'replaceable'),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'expires_at')),
            Row::strOrNull($row, 'policy'),
            TrafficClass::from(Row::str($row, 'traffic_class')),
            $originType === null && $originId === null && $notification === null ? null : new EffectOrigin($originType, $originId, $notification),
            Row::strOrNull($row, 'campaign_id'),
            LedgerTime::date(Row::int($row, 'dedupe_until')),
            LedgerTime::date(Row::int($row, 'created_at')),
            Row::strOrNull($row, 'message') !== null,
        );
    }
}
