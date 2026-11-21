<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Laravel\Campaigns\CampaignException;

/**
 * Audience classes by stored name. A campaign row stores the alias when the class is registered (so a host
 * application can rename its class and keep the alias), otherwise the fully qualified class name.
 *
 * Built-ins: `devices`, `list`, `file`. Host classes: AudienceRegistry::register('vip', VipAudience::class) in a
 * service provider, or the mobile-push.campaigns.audiences config map (alias => class). Only classes that implement
 * Audience are ever instantiated from a stored name.
 */
final class AudienceRegistry
{
    private const BUILT_IN = [
        'devices' => DeviceAudience::class,
        'list' => DestinationListAudience::class,
        'file' => DestinationFileAudience::class,
    ];

    /** @var array<string, class-string<Audience>> */
    private array $aliases;

    /** @var array<string, class-string<Audience>> */
    private static array $registered = [];

    /** @param  array<mixed>  $configured  alias => class from the config */
    public function __construct(array $configured = [])
    {
        $aliases = self::BUILT_IN;
        foreach ($configured as $alias => $class) {
            if (is_string($alias) && is_string($class)) {
                $aliases[$alias] = self::assertAudience($class);
            }
        }
        $this->aliases = array_replace($aliases, self::$registered);
    }

    /** @param  class-string<Audience>  $class */
    public static function register(string $alias, string $class): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('An audience alias is 1-64 lowercase characters.');
        }
        self::$registered[$alias] = self::assertAudience($class);
    }

    /** Forget the aliases registered at runtime (tests). */
    public static function flush(): void
    {
        self::$registered = [];
    }

    /** The name to store for this audience. */
    public function nameOf(Audience $audience): string
    {
        $alias = array_search($audience::class, $this->aliases, true);

        return is_string($alias) ? $alias : $audience::class;
    }

    /** @param  array<string, mixed>  $parameters */
    public function make(string $name, array $parameters): Audience
    {
        $class = $this->aliases[$name] ?? (class_exists($name) ? self::assertAudience($name) : null);
        if ($class === null) {
            throw new CampaignException('Unknown campaign audience "'.$name.'": register it with AudienceRegistry::register().');
        }

        return $class::fromArray($parameters);
    }

    /** @return class-string<Audience> */
    private static function assertAudience(string $class): string
    {
        if (! is_subclass_of($class, Audience::class)) {
            throw new CampaignException('A campaign audience class must implement '.Audience::class.'.');
        }

        return $class;
    }
}
