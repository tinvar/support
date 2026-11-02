<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Where an application keeps what it generates for itself and must never
 * keep inside its own directory: the app key, the probe token, and the
 * secrets `tinvar new` and `tinvar deploy:init` generate for a deployment
 * (ADR 0082, 0157).
 *
 * One file per application on this machine and per kind, in this user's
 * state directory -- `$XDG_STATE_HOME/tinvar/`, else `~/.local/state/tinvar/`
 * -- named for the application's directory and a hash of its real path:
 *
 *     ~/.local/state/tinvar/my-app-2cab79174cf3.key
 *     ~/.local/state/tinvar/my-app-2cab79174cf3.probe-token
 *
 * so two applications made from the skeleton never share a key, and a
 * secret is never in a directory a copy, an archive or an image build
 * carries away. A relative XDG_STATE_HOME is ignored, as the XDG spec says;
 * with neither it nor an absolute HOME there is no state directory, and
 * the caller says what to set instead.
 */
final class StateFile
{
    /**
     * The file of $extension -- `key`, `probe-token` -- for the application
     * in $appRoot, or null where this user has no state directory.
     *
     * @param array<string, string>|null $env the environment read; the process's own by default
     */
    public static function of(string $appRoot, string $extension, ?array $env = null): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]*\z/', $extension) !== 1) {
            throw new \InvalidArgumentException("'{$extension}' is not a state file's extension: lowercase letters, digits, dots and dashes.");
        }
        $directory = self::directory($env);
        if ($directory === null) {
            return null;
        }
        $root = realpath($appRoot) ?: $appRoot;

        return "{$directory}/" . self::stem($root) . ".{$extension}";
    }

    /**
     * This user's state directory for Tinvar, `$XDG_STATE_HOME/tinvar` or
     * `~/.local/state/tinvar`; null with neither variable absolute.
     *
     * @param array<string, string>|null $env
     */
    public static function directory(?array $env = null): ?string
    {
        $read = static fn (string $name): string => (string) ($env === null ? getenv($name) : ($env[$name] ?? ''));
        $state = $read('XDG_STATE_HOME');
        if (str_starts_with($state, '/')) {
            return rtrim($state, '/') . '/tinvar';
        }
        $home = $read('HOME');
        if (str_starts_with($home, '/')) {
            return rtrim($home, '/') . '/.local/state/tinvar';
        }

        return null;
    }

    /**
     * The name every one of an application's state files starts with: its
     * directory's name, made safe, and the first twelve hex digits of the
     * SHA-256 of its real path.
     */
    public static function stem(string $realRoot): string
    {
        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower(basename($realRoot))) ?? 'app';

        return ($name === '' ? 'app' : $name) . '-' . substr(hash('sha256', $realRoot), 0, 12);
    }
}
