<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Laravel\Sending\PendingSend;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Laravel notification channel for push: registered as "push" (all providers, `toPush()`), "apns" (iOS only,
 * `toApns()`) and "fcm" (Android only, `toFcm()`). `toApns()` / `toFcm()` may return a native message or a
 * PushMessage; when they are missing the channel falls back to `toPush()`.
 *
 * Destinations: the notifiable's `routeNotificationForPush($notification)` (typed destinations, never raw
 * strings), else its device registry (HasPushDevices), else, for on-demand notifications, the route given with
 * Notification::route('push', ...). A notifiable without any destination produces an empty report, never an error.
 * The "apns" and "fcm" channels keep only the destinations/connections of their provider.
 *
 * ONE OWNER OF QUEUEING (never two jobs for one push):
 *  - the notification implements ShouldQueue: Laravel's SendQueuedNotifications job is the owner and the push is
 *    sent INLINE inside it (the channel never calls PendingSend::queue());
 *  - the notification is not queued but implements QueuesPush (or its pushQueue() hook returns true/an options
 *    array): the channel hands the push to PendingSend::queue() and nothing is sent inline; the returned report is
 *    empty and no fallback is evaluated (the outcome is not known yet: use FallbackEvaluator when it is);
 *  - otherwise the push is sent inline.
 *
 * Optional hooks on the notification: pushConnection(), pushScope(), pushEffectKey(), pushOptions(), pushFallback(),
 * pushQueue() (see the interfaces of this namespace). The effect key defaults to the notification id combined with
 * the notifiable (EffectKeys), so a retried queued notification reuses the same provider message ids.
 *
 * The returned SendReport is consumed (counts are final) and becomes the `response` of NotificationSent. Failures
 * of single destinations are results, never exceptions; a globally invalid message or configuration throws.
 */
final class PushChannel
{
    public function __construct(
        private readonly PushManager $manager,
        private readonly FallbackEvaluator $fallback,
        private readonly ?Provider $provider = null,
    ) {}

    public function send(mixed $notifiable, Notification $notification): SendReport
    {
        if (! is_object($notifiable)) {
            throw new InvalidDestinationException('The push channel needs a notifiable object.');
        }

        $message = $this->message($notifiable, $notification);
        $effectKey = EffectKeys::forPush($notifiable, $notification);
        $pendings = $this->pendings($notifiable, $notification, $effectKey);

        $queue = self::queueOptions($notifiable, $notification);
        if ($queue !== null) {
            foreach ($pendings as $pending) {
                $pending = $pending->onQueueConnection($queue['connection'])->onQueue($queue['queue']);
                if ($queue['delay'] !== null) {
                    $pending = $pending->delay($queue['delay']);
                }
                if ($queue['after_commit'] !== null) {
                    $pending = $pending->afterCommit($queue['after_commit']);
                }
                $pending->queue($message);
            }

            return new SendReport([]);
        }

        $facts = new FallbackFacts;
        $merged = new SendReport((static function () use ($pendings, $message, $facts): \Generator {
            foreach ($pendings as $pending) {
                foreach ($pending->send($message)->results() as $result) {
                    $facts->observe($result);

                    yield $result;
                }
            }
        })());
        $merged->count(); // consume

        if ($notification instanceof HasPushFallback || method_exists($notification, 'pushFallback')) {
            $this->fallback->evaluate($notifiable, $notification, $facts, $effectKey);
        }

        return $merged;
    }

    private function message(object $notifiable, Notification $notification): Message
    {
        $methods = match ($this->provider) {
            null => ['toPush'],
            Provider::Apns => ['toApns', 'toPush'],
            Provider::Fcm => ['toFcm', 'toPush'],
        };
        foreach ($methods as $method) {
            if (method_exists($notification, $method)) {
                $message = $notification->{$method}($notifiable);
                if (! $message instanceof Message) {
                    throw new InvalidMessageException(sprintf('%s::%s() must return a PushMessage or a native push message, %s given.', $notification::class, $method, get_debug_type($message)));
                }

                return $message;
            }
        }

        throw new InvalidMessageException(sprintf('%s cannot be sent on the "%s" channel: define %s().', $notification::class, $this->name(), $methods[0]));
    }

    private function name(): string
    {
        return $this->provider === null ? 'push' : $this->provider->value;
    }

    /**
     * @return array{connection: ?string, queue: ?string, delay: \DateTimeInterface|int|null, after_commit: ?bool}|null
     */
    private static function queueOptions(object $notifiable, Notification $notification): ?array
    {
        if ($notification instanceof ShouldQueue) {
            return null; // Laravel's job owns queueing; this runs inside it
        }
        $wanted = $notification instanceof QueuesPush;
        $options = [];
        if (method_exists($notification, 'pushQueue')) {
            $answer = $notification->pushQueue($notifiable);
            if (is_array($answer)) {
                $wanted = true;
                $options = $answer;
            } elseif ($answer !== null) {
                $wanted = (bool) $answer;
            }
        }
        if (! $wanted) {
            return null;
        }
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? null;
        $delay = $options['delay'] ?? null;
        $afterCommit = $options['after_commit'] ?? null;

        return [
            'connection' => is_string($connection) ? $connection : null,
            'queue' => is_string($queue) ? $queue : null,
            'delay' => is_int($delay) || $delay instanceof \DateTimeInterface ? $delay : null,
            'after_commit' => is_bool($afterCommit) ? $afterCommit : null,
        ];
    }

    /**
     * @return list<PendingSend>
     */
    private function pendings(object $notifiable, Notification $notification, ?string $effectKey): array
    {
        $scope = method_exists($notification, 'pushScope') ? $notification->pushScope($notifiable) : null;
        $scope = is_string($scope) && $scope !== '' ? $scope : null;
        $named = method_exists($notification, 'pushConnection') ? $notification->pushConnection($notifiable) : null;
        $named = is_string($named) && $named !== '' ? $named : null;
        $resolvedScope = $scope ?? $this->manager->currentScope();

        if ($named !== null && $this->provider !== null && $this->manager->providerOf($resolvedScope, $named) !== $this->provider) {
            throw new ConfigurationException(sprintf('%s::pushConnection() names the connection "%s", which is not an %s connection: it cannot be used on the "%s" channel.', $notification::class, $named, $this->provider->value, $this->name()));
        }

        $make = function (?string $connection) use ($scope, $effectKey): PendingSend {
            $pending = $this->manager->connection($connection);
            if ($scope !== null) {
                $pending = $pending->scope($scope);
            }
            if ($effectKey !== null) {
                $pending = $pending->effectKey($effectKey);
            }

            return $pending;
        };
        $finish = function (PendingSend $pending) use ($notification, $notifiable): PendingSend {
            if (method_exists($notification, 'pushOptions')) {
                $customised = $notification->pushOptions($pending, $notifiable);
                if (! $customised instanceof PendingSend) {
                    throw new \UnexpectedValueException(sprintf('%s::pushOptions() must return the PendingSend.', $notification::class));
                }

                return $customised;
            }

            return $pending;
        };

        $routed = $this->routed($notifiable, $notification);
        if ($routed !== null) {
            return $routed === [] ? [] : [$finish($make($named)->to($routed))];
        }
        if (! method_exists($notifiable, 'pushDevices')) {
            return [];
        }

        // Registry: one pending per connection of the provider (or the one named).
        if ($named !== null) {
            return [$finish($make($named)->to($notifiable))];
        }
        if ($this->provider === null) {
            return [$finish($make(null)->to($notifiable))];
        }
        $pendings = [];
        foreach ($this->manager->routeNames($resolvedScope) as $name) {
            if ($this->manager->providerOf($resolvedScope, $name) === $this->provider) {
                $pendings[] = $finish($make($name)->to($notifiable));
            }
        }

        return $pendings;
    }

    /**
     * Typed destinations from routeNotificationForPush() / an on-demand route, restricted to this channel's
     * provider; null when the notifiable has no such routing (the registry is used instead).
     *
     * @return list<Destination|SendItem>|null
     */
    private function routed(object $notifiable, Notification $notification): ?array
    {
        if (method_exists($notifiable, 'routeNotificationForPush')) {
            $routed = $notifiable->routeNotificationForPush($notification);
        } elseif ($notifiable instanceof AnonymousNotifiable) {
            $routed = $notifiable->routeNotificationFor('push');
        } else {
            return null;
        }

        $out = [];
        foreach ($routed === null ? [] : (is_iterable($routed) ? $routed : [$routed]) as $item) {
            if (! $item instanceof Destination && ! $item instanceof SendItem) {
                throw new InvalidDestinationException(sprintf('%s routes push to typed destinations (e.g. ApnsDeviceToken::of()), never raw strings.', $notifiable::class));
            }
            $destination = $item instanceof SendItem ? $item->destination : $item;
            if ($this->provider === null || $destination->kind()->provider() === $this->provider) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
