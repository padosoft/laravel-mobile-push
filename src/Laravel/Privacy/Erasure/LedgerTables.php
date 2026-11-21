<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

/** Connection and table names of the durable ledger, read the way the ledger module reads them. */
final readonly class LedgerTables
{
    public function __construct(
        public ?string $connection = null,
        public string $effects = 'mobile_push_effects',
        public string $deliveries = 'mobile_push_deliveries',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            self::string('mobile-push.ledger.connection', null),
            self::string('mobile-push.ledger.tables.effects', 'mobile_push_effects') ?? 'mobile_push_effects',
            self::string('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries') ?? 'mobile_push_deliveries',
        );
    }

    private static function string(string $key, ?string $default): ?string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
