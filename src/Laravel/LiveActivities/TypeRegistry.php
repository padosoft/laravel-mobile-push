<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Contracts\Container\Container;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Resolves Live Activity types and their stable keys.
 *
 * Key rule: the key under which a type is registered in `mobile-push.live_activities.types` (key => class), or
 * otherwise its Swift name. The key, the Swift name and the version are stored with every row, so renaming a PHP
 * class never changes what is sent: the Swift name sent as `attributes-type` is always `swiftName()`.
 */
final readonly class TypeRegistry
{
    public function __construct(private Container $container) {}

    /** @param  LiveActivityType|string  $type  an instance, a registered key, or a class implementing LiveActivityType */
    public function resolve(LiveActivityType|string $type): LiveActivityType
    {
        if ($type instanceof LiveActivityType) {
            return $type;
        }

        $class = $this->types()[$type] ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, LiveActivityType::class)) {
            throw new ConfigurationException("Unknown Live Activity type \"{$type}\": register it in mobile-push.live_activities.types or pass a class implementing LiveActivityType.");
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof LiveActivityType) {
            throw new ConfigurationException("The Live Activity type \"{$type}\" does not resolve to a LiveActivityType.");
        }

        $this->assertValid($instance);

        return $instance;
    }

    public function keyOf(LiveActivityType $type): string
    {
        foreach ($this->types() as $key => $class) {
            if ($type instanceof $class) {
                return $key;
            }
        }

        $this->assertValid($type);

        return $type->swiftName();
    }

    /** The registered type for a stored key, or null (the key is then the Swift name of an unregistered type). */
    public function find(string $key): ?LiveActivityType
    {
        $class = $this->types()[$key] ?? null;

        return $class === null ? null : $this->resolve($class);
    }

    private function assertValid(LiveActivityType $type): void
    {
        $name = $type->swiftName();
        if (trim($name) === '' || strlen($name) > 191) {
            throw new ConfigurationException('A Live Activity type needs a Swift name of 1 to 191 characters.');
        }
        if ($type->version() < 0) {
            throw new ConfigurationException('A Live Activity type version cannot be negative.');
        }
    }

    /** @return array<string, string> */
    private function types(): array
    {
        $types = config('mobile-push.live_activities.types', []);
        $out = [];

        if (is_array($types)) {
            foreach ($types as $key => $class) {
                if (is_string($key) && is_string($class)) {
                    $out[$key] = $class;
                }
            }
        }

        return $out;
    }
}
