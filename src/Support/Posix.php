<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Who this process is, for the checks that decide whom to trust: the
 * application key's file and directory, the renderer's socket and pool, a
 * test run's private files (ADR 0212).
 *
 * Every one of those checks compares an owner with this process's user,
 * and every one of them used to be skipped where ext-posix was not loaded:
 * a key file anyone could have planted was believed, and a socket any
 * local user could have put in /tmp was sent renders. The packages require
 * ext-posix now, and the check fails closed where it is missing anyway --
 * a build of PHP without it, or one where it is disabled -- rather than
 * quietly trusting everyone.
 */
final class Posix
{
    /**
     * This process's effective user id.
     *
     * @param string $for what is being checked, for the message: "the application key file"
     * @throws \RuntimeException where ext-posix is not loaded
     */
    public static function effectiveUser(string $for): int
    {
        if (!\function_exists('posix_geteuid')) {
            throw new \RuntimeException(
                "Refusing {$for}: ext-posix is not loaded, so PHP cannot check who owns it, and the check is never skipped. "
                . 'Install or enable the posix extension (Tinvar requires it).',
            );
        }

        return posix_geteuid();
    }
}
