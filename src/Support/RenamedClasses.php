<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * The names Tinvar's classes had before a release renamed them, and the
 * names they have now: what each package's aliases.php records when it
 * loads (ADR 0055, 0056).
 *
 * Kept apart from the aliases themselves, so a refusal can say what an old
 * name became where no alias is made for it -- once the aliases are gone,
 * or with no autoloader to make them. Written at boot, by files Composer
 * autoloads, and only read after: the same for every request a worker
 * serves.
 */
final class RenamedClasses
{
    /** @var array<string, string> lowercased old name => new name */
    private static array $renamed = [];

    /**
     * Records old names and what they became.
     *
     * @param array<string, string> $renamed old name, lowercased with no leading `\`, => new name
     */
    public static function record(array $renamed): void
    {
        self::$renamed = [...self::$renamed, ...$renamed];
    }

    /** What $class is named now, or null for a name no release renamed. */
    public static function current(string $class): ?string
    {
        return self::$renamed[strtolower(ltrim($class, '\\'))] ?? null;
    }
}
