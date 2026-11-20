<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

/**
 * Decides, BEFORE any external effect, whether one operation may touch the network.
 *
 * - Simulate: never (result "simulated").
 * - Live in production: yes, once the operation is consistent with the binding.
 * - Live elsewhere: only with `allow` and a destination whose index is in the allowlist; topics, conditions,
 *   broadcast channels and channel management additionally need their explicit authorisation.
 *
 * An inconsistency always blocks. It never degrades into a real send.
 */
final readonly class SafeModeGuard
{
    public function __construct(private TokenIndex $index) {}

    /**
     * @param  Environment|null  $requestedEnvironment  the provider environment the caller asked for (for example a
     *                                                  `--sandbox` command flag); null when the caller expressed none
     */
    public function decide(
        ExecutionMode $mode,
        string $applicationEnvironment,
        BindingRef $binding,
        ?Destination $destination,
        OperationKind $operation,
        LiveSettings $live,
        ?Environment $requestedEnvironment = null,
    ): SafeModeDecision {
        if ($mode === ExecutionMode::Simulate) {
            return SafeModeDecision::simulate(
                'Simulation mode: no request was sent. To send for real set MOBILE_PUSH_MODE=live'
                .' (outside production also set the binding\'s live.allow=true and add the test device to live.only_to).',
            );
        }

        // Consistency first: a contradiction blocks in every environment, production included.
        if ($requestedEnvironment !== null && $requestedEnvironment !== $binding->environment) {
            return SafeModeDecision::block($mode, sprintf(
                'Blocked: the operation targets the "%s" provider environment but the binding "%s" is configured for "%s". No connection was opened.',
                $requestedEnvironment->value, $binding->name, $binding->environment->value,
            ));
        }

        if ($destination !== null && $destination->kind()->provider() !== $binding->provider) {
            return SafeModeDecision::block($mode, 'Blocked: the destination belongs to a different provider than the binding.');
        }

        if ($destination !== null && ! $this->kindMatchesOperation($destination->kind(), $operation)) {
            return SafeModeDecision::block($mode, sprintf(
                'Blocked: a "%s" destination cannot be used for the "%s" operation.',
                $destination->kind()->value, $operation->value,
            ));
        }

        if ($applicationEnvironment === ModeResolver::PRODUCTION) {
            return SafeModeDecision::allow($mode);
        }

        $how = 'To allow it set live.allow=true on the binding'.($this->needsAllowlist($operation, $destination) ? ' and add the test device to live.only_to' : '').'.';

        if (! $live->allow) {
            return SafeModeDecision::block($mode, "Blocked: live sending outside production is not enabled for this binding. {$how}");
        }

        $explicit = match (true) {
            $operation === OperationKind::ChannelManagement => [$live->allowChannelManagement, 'live.allow_channel_management'],
            $operation === OperationKind::BroadcastPublish || $destination?->kind() === DestinationKind::ApnsBroadcastChannel => [$live->allowBroadcast, 'live.allow_broadcast'],
            $operation === OperationKind::TopicSubscription || $destination?->kind() === DestinationKind::FcmTopic || $destination?->kind() === DestinationKind::FcmCondition => [$live->allowTopics, 'live.allow_topics'],
            default => null,
        };

        if ($explicit !== null && ! $explicit[0]) {
            return SafeModeDecision::block($mode, "Blocked: this operation reaches many devices or manages provider state and needs explicit authorisation ({$explicit[1]}=true).");
        }

        if ($this->needsAllowlist($operation, $destination) && $destination !== null && ! $this->isAllowlisted($destination, $binding, $live)) {
            return SafeModeDecision::block($mode, 'Blocked: the destination is not in this binding\'s live.only_to allowlist of test devices.');
        }

        return SafeModeDecision::allow($mode);
    }

    private function isAllowlisted(Destination $destination, BindingRef $binding, LiveSettings $live): bool
    {
        foreach ($this->index->candidates($destination, $binding->scope) as $candidate) {
            foreach ($live->onlyTo as $allowed) {
                if (hash_equals($allowed, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Individual destinations are matched against the allowlist; groups rely on their explicit authorisation. */
    private function needsAllowlist(OperationKind $operation, ?Destination $destination): bool
    {
        if ($destination === null) {
            return false;
        }

        return $destination->kind()->isIndividual();
    }

    private function kindMatchesOperation(DestinationKind $kind, OperationKind $operation): bool
    {
        return match ($operation) {
            OperationKind::BroadcastPublish => $kind === DestinationKind::ApnsBroadcastChannel,
            OperationKind::TopicSubscription => $kind === DestinationKind::FcmToken || $kind === DestinationKind::FcmFid || $kind === DestinationKind::FcmTopic,
            default => true,
        };
    }
}
