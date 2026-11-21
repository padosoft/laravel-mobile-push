<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;

/**
 * The final result of one destination for one send call. Dispatched according to `mobile-push.events.per_result`
 * (auto = transactional traffic only, always, never).
 *
 * `$result` is the safe view (serializable, no raw token). In-process listeners that need the typed destination
 * can call sendResult(); it is kept out of serialization, var_export() and print_r(), so a queued listener gets
 * null there.
 */
final class PushResultProduced
{
    /** @var \SplObjectStorage<\stdClass, SendResult> */
    private \SplObjectStorage $vault;

    private \stdClass $anchor;

    public function __construct(
        public readonly BindingRef $binding,
        public readonly ResultView $result,
        public readonly TrafficClass $trafficClass,
        SendResult $sendResult,
        /** The stored effect this result belongs to: set for a queued send (the id `queue()` returned), null otherwise. */
        public readonly ?string $effectId = null,
    ) {
        $this->vault = new \SplObjectStorage;
        $this->anchor = new \stdClass;
        $this->vault[$this->anchor] = $sendResult;
    }

    /** The typed result, in-process only (null after the event was serialized). */
    public function sendResult(): ?SendResult
    {
        return isset($this->vault[$this->anchor]) ? $this->vault[$this->anchor] : null;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['binding' => $this->binding, 'result' => $this->result, 'trafficClass' => $this->trafficClass, 'effectId' => $this->effectId];
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        $binding = $data['binding'] ?? null;
        $result = $data['result'] ?? null;
        $class = $data['trafficClass'] ?? null;
        if (! $binding instanceof BindingRef || ! $result instanceof ResultView || ! $class instanceof TrafficClass) {
            throw new \UnexpectedValueException('Malformed PushResultProduced payload.');
        }
        $this->binding = $binding;
        $this->result = $result;
        $this->trafficClass = $class;
        $effectId = $data['effectId'] ?? null;
        $this->effectId = is_string($effectId) ? $effectId : null;
        $this->vault = new \SplObjectStorage;
        $this->anchor = new \stdClass;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->__serialize();
    }
}
