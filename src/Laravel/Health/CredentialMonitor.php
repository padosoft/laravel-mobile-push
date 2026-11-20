<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\Finding;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Psr\Clock\ClockInterface;

/**
 * Service behind `mobile-push:credentials:check`: inspects every connection of the ConnectionCredentialsSource with
 * CredentialCheck and (optionally) raises expiring/expired alerts. The warning window is `credentials.warn_days`
 * (carried by the CredentialCheck instance). Makes no request to any provider.
 */
final class CredentialMonitor
{
    public function __construct(
        private readonly ConnectionCredentialsSource $source,
        private readonly CredentialCheck $check,
        private readonly OpsAlerter $alerter,
        private readonly ClockInterface $clock,
        private readonly Redactor $redactor,
    ) {}

    /** False when no source is bound: the command should say credentials cannot be verified from here. */
    public function available(): bool
    {
        return $this->source->available();
    }

    /**
     * @return list<CredentialFindings>
     */
    public function check(bool $alert = true): array
    {
        if (! $this->source->available()) {
            return [];
        }

        $out = [];
        foreach ($this->source->connections() as $connection) {
            if ($connection->error !== null) {
                $result = new CheckResult([Finding::error('credentials.unreadable', 'The credentials of this connection cannot be read: '.$this->redactor->string($connection->error).'. Check the secret path or value in the connection configuration.')]);
                $out[] = new CredentialFindings($connection->binding, $result, null, $connection->error);

                continue;
            }
            $result = $connection->evaluate($this->check);
            $alerts = $alert ? $this->alerter->credentialFindings($connection->binding, $result) : [];
            $out[] = new CredentialFindings($connection->binding, $result, $connection->certificate?->daysRemaining($this->clock->now()), null, $alerts);
        }

        return $out;
    }
}
