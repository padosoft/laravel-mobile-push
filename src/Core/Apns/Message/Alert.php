<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Message;

/**
 * The `aps.alert` dictionary. Immutable; every field is optional and null means "not set", which is what
 * lets an overlay record only what it changes.
 */
final readonly class Alert
{
    /**
     * @param  list<string>|null  $titleLocArgs
     * @param  list<string>|null  $subtitleLocArgs
     * @param  list<string>|null  $locArgs
     */
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $body = null,
        public ?string $launchImage = null,
        public ?string $titleLocKey = null,
        public ?array $titleLocArgs = null,
        public ?string $subtitleLocKey = null,
        public ?array $subtitleLocArgs = null,
        public ?string $locKey = null,
        public ?array $locArgs = null,
    ) {}

    public static function make(?string $title = null, ?string $body = null, ?string $subtitle = null): self
    {
        return new self(title: $title, subtitle: $subtitle, body: $body);
    }

    /** @param  list<string>  $args */
    public static function localized(string $locKey, array $args = []): self
    {
        return new self(locKey: $locKey, locArgs: $args === [] ? null : $args);
    }

    public function withTitle(?string $title): self
    {
        return $this->copy(['title' => $title]);
    }

    public function withSubtitle(?string $subtitle): self
    {
        return $this->copy(['subtitle' => $subtitle]);
    }

    public function withBody(?string $body): self
    {
        return $this->copy(['body' => $body]);
    }

    public function withLaunchImage(?string $image): self
    {
        return $this->copy(['launchImage' => $image]);
    }

    /** @param  list<string>  $args */
    public function withTitleLocalization(string $key, array $args = []): self
    {
        return $this->copy(['titleLocKey' => $key, 'titleLocArgs' => $args === [] ? null : $args]);
    }

    /** @param  list<string>  $args */
    public function withSubtitleLocalization(string $key, array $args = []): self
    {
        return $this->copy(['subtitleLocKey' => $key, 'subtitleLocArgs' => $args === [] ? null : $args]);
    }

    /** @param  list<string>  $args */
    public function withBodyLocalization(string $key, array $args = []): self
    {
        return $this->copy(['locKey' => $key, 'locArgs' => $args === [] ? null : $args]);
    }

    /** The fields of `$other` that are set win over this alert's. */
    public function mergedWith(self $other): self
    {
        return new self(
            $other->title ?? $this->title,
            $other->subtitle ?? $this->subtitle,
            $other->body ?? $this->body,
            $other->launchImage ?? $this->launchImage,
            $other->titleLocKey ?? $this->titleLocKey,
            $other->titleLocArgs ?? $this->titleLocArgs,
            $other->subtitleLocKey ?? $this->subtitleLocKey,
            $other->subtitleLocArgs ?? $this->subtitleLocArgs,
            $other->locKey ?? $this->locKey,
            $other->locArgs ?? $this->locArgs,
        );
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** @return array<string, string|list<string>> only the fields that are set, with Apple's key names */
    public function toArray(): array
    {
        $map = [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'body' => $this->body,
            'launch-image' => $this->launchImage,
            'title-loc-key' => $this->titleLocKey,
            'title-loc-args' => $this->titleLocArgs,
            'subtitle-loc-key' => $this->subtitleLocKey,
            'subtitle-loc-args' => $this->subtitleLocArgs,
            'loc-key' => $this->locKey,
            'loc-args' => $this->locArgs,
        ];

        return array_filter($map, static fn (string|array|null $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $changes */
    private function copy(array $changes): self
    {
        /** @var array{title?: ?string, subtitle?: ?string, body?: ?string, launchImage?: ?string, titleLocKey?: ?string, titleLocArgs?: ?list<string>, subtitleLocKey?: ?string, subtitleLocArgs?: ?list<string>, locKey?: ?string, locArgs?: ?list<string>} $changes */
        return new self(
            array_key_exists('title', $changes) ? $changes['title'] : $this->title,
            array_key_exists('subtitle', $changes) ? $changes['subtitle'] : $this->subtitle,
            array_key_exists('body', $changes) ? $changes['body'] : $this->body,
            array_key_exists('launchImage', $changes) ? $changes['launchImage'] : $this->launchImage,
            array_key_exists('titleLocKey', $changes) ? $changes['titleLocKey'] : $this->titleLocKey,
            array_key_exists('titleLocArgs', $changes) ? $changes['titleLocArgs'] : $this->titleLocArgs,
            array_key_exists('subtitleLocKey', $changes) ? $changes['subtitleLocKey'] : $this->subtitleLocKey,
            array_key_exists('subtitleLocArgs', $changes) ? $changes['subtitleLocArgs'] : $this->subtitleLocArgs,
            array_key_exists('locKey', $changes) ? $changes['locKey'] : $this->locKey,
            array_key_exists('locArgs', $changes) ? $changes['locArgs'] : $this->locArgs,
        );
    }
}
