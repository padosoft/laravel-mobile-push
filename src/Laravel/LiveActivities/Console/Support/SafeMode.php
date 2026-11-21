<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console\Support;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;

/**
 * Asks the safe-mode guard BEFORE a command touches anything: same guard, same options the send pipeline and the
 * channel client use, so a command never writes a row or opens a connection the pipeline would refuse.
 */
final readonly class SafeMode
{
    public function __construct(private PushDispatcher $dispatcher, private TokenIndex $index) {}

    public function decide(BindingRef $binding, OperationKind $operation, ?Destination $destination = null): SafeModeDecision
    {
        $options = $this->dispatcher->defaultOptions($binding);

        return (new SafeModeGuard($this->index))->decide(
            $options->effectiveMode,
            $options->applicationEnvironment ?? '',
            $binding,
            $destination,
            $operation,
            $options->live,
        );
    }

    /** The binding a stored row was created for, or null when its connection no longer resolves to the same identity. */
    public function bindingOf(Model $row): ?BindingRef
    {
        $name = $row->getAttribute('binding_name');
        $scope = $row->getAttribute('scope');
        if (! is_string($name) || ! is_string($scope)) {
            return null;
        }

        try {
            $binding = $this->dispatcher->binding($name, $scope);
        } catch (ConfigurationException) {
            return null;
        }

        return $binding->identity() === $row->getAttribute('binding_identity') ? $binding : null;
    }
}
