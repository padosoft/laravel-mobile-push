<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Environment;

/**
 * The ONLY source of provider URLs. No URL comes from configuration or from a message.
 *
 * Apple documentation, verified 2026-11-20 (developer.apple.com/tutorials/data/documentation/usernotifications):
 *
 * sending-channel-management-requests-to-apns:
 *   "Development/Sandbox Environment: api-manage-broadcast.sandbox.push.apple.com:2195"
 *   "Production Environment: api-manage-broadcast.push.apple.com:2196"
 *   create: POST   /1/apps/{bundleId}/channels            (201, header apns-channel-id in the response)
 *   read:   GET    /1/apps/{bundleId}/channels            (request header apns-channel-id)
 *   delete: DELETE /1/apps/{bundleId}/channels            (request header apns-channel-id, 204)
 *   list:   GET    /1/apps/{bundleId}/all-channels
 *
 * sending-broadcast-push-notification-requests-to-apns:
 *   connection section: "Development/Sandbox Environment: api.sandbox.push.apple.com:443",
 *                       "Production Environment: api.push.apple.com:443"
 *   samples: ":path = 4/broadcasts/apps/com.example.MyApp" (sic: no leading slash in the page; "/4/..." is used here)
 *            "host = api-broadcast.sandbox.push.apple.com"; header apns-channel-id; apns-push-type = liveactivity.
 *   The two statements disagree, hence ApnsBroadcastHost (standard | api-broadcast). The production name
 *   "api-broadcast.push.apple.com" is the symmetric form, NOT stated on the page.
 *
 * APNs send: api.push.apple.com / api.sandbox.push.apple.com on 443 or 2197 (Apple's connection guide, not re-fetched
 * here), path /3/device/{token}.
 * FCM (Discovery rev. 20261112): POST https://fcm.googleapis.com/v1/projects/{project}/messages:send.
 * Topic subscriptions resource: v1/projects/{project}/registrations/{id}/topicSubscriptions (unverified details).
 */
final readonly class EndpointCatalog
{
    public const APNS_PORTS = [443, 2197];

    private const APNS_HOSTS = [
        'production' => 'api.push.apple.com',
        'sandbox' => 'api.sandbox.push.apple.com',
    ];

    private const APNS_MANAGE = [
        'production' => ['api-manage-broadcast.push.apple.com', 2196],
        'sandbox' => ['api-manage-broadcast.sandbox.push.apple.com', 2195],
    ];

    private const APNS_BROADCAST = [
        'production' => 'api-broadcast.push.apple.com',
        'sandbox' => 'api-broadcast.sandbox.push.apple.com',
    ];

    private const FCM_HOST = 'fcm.googleapis.com';

    private const GOOGLE_TOKEN_HOST = 'oauth2.googleapis.com';

    public function __construct(
        private string $applicationEnvironment,
        private ApnsBroadcastHost $broadcastHost = ApnsBroadcastHost::Standard,
        private int $apnsPort = 443,
        private ?EndpointOverride $override = null,
    ) {
        if (! in_array($apnsPort, self::APNS_PORTS, true)) {
            throw new ConfigurationException('The APNs port must be 443 or 2197.');
        }

        // An unrecognised spelling must fall on the safe side here: "Production", " production" or "prod" is still a
        // production system, and a custom host is never acceptable there.
        $production = in_array(strtolower(trim($applicationEnvironment)), [ModeResolver::PRODUCTION, 'prod'], true);
        if ($override !== null && (! $override->testCredentials || $production)) {
            throw new ConfigurationException('Endpoint overrides are accepted only with test credentials and never in production.');
        }
    }

    public function apnsSendUrl(Environment $environment, #[\SensitiveParameter] string $deviceToken): string
    {
        return $this->origin(EndpointRole::ApnsSend, $environment).'/3/device/'.self::segment($deviceToken, '/^[A-Za-z0-9_-]{1,512}$/', 'device token');
    }

    public function apnsBroadcastPublishUrl(Environment $environment, string $bundleId): string
    {
        return $this->origin(EndpointRole::ApnsBroadcastPublish, $environment).'/4/broadcasts/apps/'.self::bundle($bundleId);
    }

    /** Create (POST), read (GET) and delete (DELETE) use this URL; the channel id travels in `apns-channel-id`. */
    public function apnsChannelsUrl(Environment $environment, string $bundleId): string
    {
        return $this->origin(EndpointRole::ApnsChannelManagement, $environment).'/1/apps/'.self::bundle($bundleId).'/channels';
    }

    public function apnsAllChannelsUrl(Environment $environment, string $bundleId): string
    {
        return $this->origin(EndpointRole::ApnsChannelManagement, $environment).'/1/apps/'.self::bundle($bundleId).'/all-channels';
    }

    public function fcmSendUrl(string $projectId): string
    {
        return $this->origin(EndpointRole::FcmApi, null).'/v1/projects/'.self::project($projectId).'/messages:send';
    }

    public function fcmTopicSubscriptionUrl(string $projectId, #[\SensitiveParameter] string $registrationId): string
    {
        return $this->origin(EndpointRole::FcmApi, null).'/v1/projects/'.self::project($projectId)
            .'/registrations/'.self::segment($registrationId, '/^[A-Za-z0-9_:.-]{1,1024}$/', 'registration id').'/topicSubscriptions';
    }

    /** One topic subscription of one installation: `.../registrations/{id}/topicSubscriptions/{topic}`. */
    public function fcmTopicSubscriptionItemUrl(string $projectId, #[\SensitiveParameter] string $registrationId, string $topic): string
    {
        return $this->fcmTopicSubscriptionUrl($projectId, $registrationId)
            .'/'.self::segment($topic, '/^[A-Za-z0-9_.~%-]{1,900}$/', 'topic name');
    }

    public function googleTokenUrl(): string
    {
        return $this->origin(EndpointRole::GoogleTokenExchange, null).'/token';
    }

    /**
     * Throws unless the URL is https, has no user info/fragment, and its host, port and path prefix belong to the role.
     * Environment-agnostic: both the production and the sandbox host of a role are official.
     */
    public function assertOfficial(string $url, EndpointRole $role): void
    {
        if (! $this->isOfficial($url, $role)) {
            throw new ConfigurationException('The URL is not an official endpoint for the "'.$role->value.'" role.');
        }
    }

    public function isOfficial(string $url, EndpointRole $role): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            return false;
        }

        $path = $parts['path'];

        if (str_contains($path, '//') || preg_match('#/\.\.?(/|$)#', $path) === 1 || stripos($path, '%2e') !== false) {
            return false;
        }

        $prefix = match ($role) {
            EndpointRole::ApnsSend => '/3/device/',
            EndpointRole::ApnsChannelManagement => '/1/apps/',
            EndpointRole::ApnsBroadcastPublish => '/4/broadcasts/apps/',
            EndpointRole::FcmApi => '/v1/projects/',
            EndpointRole::GoogleTokenExchange => '/token',
        };

        if ($role === EndpointRole::GoogleTokenExchange ? $path !== $prefix : ! str_starts_with($path, $prefix)) {
            return false;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? 443;

        foreach ([Environment::Production, Environment::Sandbox] as $environment) {
            [$officialHost, $officialPorts] = $this->hostAndPorts($role, $environment);

            if ($host === $officialHost && in_array($port, $officialPorts, true)) {
                return true;
            }
        }

        return false;
    }

    private function origin(EndpointRole $role, ?Environment $environment): string
    {
        $override = $this->override?->origin($role);

        if ($override !== null) {
            return $override;
        }

        [$host, $ports] = $this->hostAndPorts($role, $environment ?? Environment::Production);
        $port = $role === EndpointRole::ApnsSend ? $this->apnsPort : $ports[0];

        return 'https://'.$host.($port === 443 ? '' : ':'.$port);
    }

    /**
     * @return array{string, list<int>}
     */
    private function hostAndPorts(EndpointRole $role, Environment $environment): array
    {
        $key = $environment->value;

        $official = match ($role) {
            EndpointRole::ApnsSend => [self::APNS_HOSTS[$key], self::APNS_PORTS],
            EndpointRole::ApnsChannelManagement => [self::APNS_MANAGE[$key][0], [self::APNS_MANAGE[$key][1]]],
            EndpointRole::ApnsBroadcastPublish => $this->broadcastHost === ApnsBroadcastHost::Standard
                ? [self::APNS_HOSTS[$key], [443]]
                : [self::APNS_BROADCAST[$key], [443]],
            EndpointRole::FcmApi => [self::FCM_HOST, [443]],
            EndpointRole::GoogleTokenExchange => [self::GOOGLE_TOKEN_HOST, [443]],
        };

        // With an override in force, its origin is official for that role too.
        $override = $this->override?->origin($role);

        if ($override !== null) {
            $parts = parse_url($override);

            return [(string) ($parts['host'] ?? ''), [$parts['port'] ?? 443]];
        }

        return $official;
    }

    private static function bundle(string $bundleId): string
    {
        if (str_contains($bundleId, '..')) {
            throw new ConfigurationException('Invalid bundle id.');
        }

        return self::segment($bundleId, '/^[A-Za-z0-9][A-Za-z0-9.-]{0,254}$/', 'bundle id');
    }

    private static function project(string $projectId): string
    {
        if (str_contains($projectId, '..')) {
            throw new ConfigurationException('Invalid project id.');
        }

        return self::segment($projectId, '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', 'project id');
    }

    /** Validate with a strict allowlist, then percent-encode: injection of "/", "?", "#", "%", ".." is impossible. */
    private static function segment(#[\SensitiveParameter] string $value, string $pattern, string $what): string
    {
        if (preg_match($pattern, $value) !== 1 || $value === '.' || $value === '..') {
            throw new ConfigurationException("Invalid {$what}: it contains characters that are not allowed in a URL path segment.");
        }

        return rawurlencode($value);
    }
}
