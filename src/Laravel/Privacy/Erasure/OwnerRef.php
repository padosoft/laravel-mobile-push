<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The person (or any notifiable) a forget or export is about: the owner type and id exactly as the registry and the
 * ledger store them (`getMorphClass()` and the key as a string).
 */
final readonly class OwnerRef
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException('An owner needs a type and an id.');
        }
        if (strlen($type) > 191 || strlen($id) > 64) {
            throw new \InvalidArgumentException('The owner type (max 191 characters) or id (max 64) is too long.');
        }
    }

    /**
     * Parse `Type:id`, splitting at the LAST colon (a class name never contains one; an id might).
     * Doubled backslashes (a shell-escaped class name) are collapsed and a leading one is dropped. A class that has a
     * morph map alias is converted to the alias, because that is what the tables store.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $argument): self
    {
        $at = strrpos($argument, ':');
        if ($at === false || $at === 0 || $at === strlen($argument) - 1) {
            throw new \InvalidArgumentException('The owner must be written Type:id, for example "App\\Models\\User:42".');
        }

        $type = ltrim(str_replace('\\\\', '\\', trim(substr($argument, 0, $at))), '\\');
        $id = trim(substr($argument, $at + 1));

        return new self(Relation::getMorphAlias($type), $id);
    }

    public function label(): string
    {
        return $this->type.':'.$this->id;
    }
}
