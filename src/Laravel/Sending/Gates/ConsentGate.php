<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Gates;

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/**
 * The host application's consent / eligibility check (MobilePush::eligibleUsing()), evaluated immediately before
 * the network, so an opt-out that happened after the push was queued still stops it.
 *
 * Two forms:
 *  - per destination (default): fn (Destination $destination, GateContext $context): bool|GateDenial
 *  - batch: fn (GateContext $context, list<SendItem> $items): array<int, bool|GateDenial|null>, keyed by the
 *    position in $items (false or a GateDenial denies; true, null or a missing position allows)
 *
 * A denial by `false` is Skipped with PolicyIneligible. Consent is never bypassed: PushMessage::bypassFrequencyCap()
 * only concerns frequency caps and is ignored here.
 */
final readonly class ConsentGate implements DestinationGate
{
    public const HINT = 'The application\'s eligibility check (MobilePush::eligibleUsing) refused this destination, for example because the user opted out.';

    /** @var \Closure(mixed, mixed): mixed */
    private \Closure $callback;

    /**
     * @param  callable(mixed, mixed): mixed  $callback  see the class docblock for the two accepted forms
     */
    public function __construct(callable $callback, private bool $batch = false)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function check(GateContext $context, array $items): array
    {
        $denials = [];

        if ($this->batch) {
            $answers = ($this->callback)($context, $items);
            if (! is_array($answers)) {
                throw new \UnexpectedValueException('A batch eligibility callback must return an array keyed by item position.');
            }
            foreach ($items as $position => $item) {
                $denial = self::denial($answers[$position] ?? null);
                if ($denial !== null) {
                    $denials[$position] = $denial;
                }
            }

            return $denials;
        }

        foreach ($items as $position => $item) {
            $denial = self::denial(($this->callback)($item->destination, $context));
            if ($denial !== null) {
                $denials[$position] = $denial;
            }
        }

        return $denials;
    }

    private static function denial(mixed $answer): ?GateDenial
    {
        return match (true) {
            $answer instanceof GateDenial => $answer,
            $answer === false => GateDenial::skip(ReasonCode::PolicyIneligible, self::HINT),
            $answer === true, $answer === null => null,
            default => throw new \UnexpectedValueException('An eligibility callback must return true, false or a GateDenial.'),
        };
    }
}
