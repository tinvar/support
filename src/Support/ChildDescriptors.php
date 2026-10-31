<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * What a child process is handed of this one's descriptors: nothing.
 *
 * Every descriptor this process holds above the three is handed on to a
 * child under its own number unless it was opened close-on-exec, which
 * PHP's files and sockets are not -- the database, the log, a key read
 * from disk, another child's tether -- and php://fd reaches each past
 * open_basedir. A renderer's permission model checks paths, never a
 * descriptor already open. So a proc_open() spec gives the child /dev/null
 * under each number instead.
 */
final class ChildDescriptors
{
    /**
     * The descriptors above 2 this process holds, from /proc/self/fd or
     * /dev/fd: the listing's own among them, which is harmless to cover.
     * Refused where neither can be read: what a child would be handed then
     * cannot be known.
     *
     * @return list<int>
     */
    public static function open(): array
    {
        foreach (['/proc/self/fd', '/dev/fd'] as $directory) {
            $names = is_dir($directory) ? scandir($directory) : false;
            if ($names !== false) {
                $fds = array_map(intval(...), array_values(array_filter($names, static fn (string $name): bool => ctype_digit($name) && (int) $name > 2)));
                sort($fds);

                return $fds;
            }
        }

        throw new \RuntimeException('Cannot list this process\'s descriptors to keep them from a child process.');
    }

    /**
     * $spec, with /dev/null for every other descriptor this process holds.
     * What $spec names itself is left as it is.
     *
     * @param array<int, mixed> $spec a proc_open() descriptor spec
     * @return array<int, mixed>
     */
    public static function closing(array $spec): array
    {
        foreach (self::open() as $fd) {
            $spec[$fd] ??= ['file', '/dev/null', 'r'];
        }

        return $spec;
    }
}
