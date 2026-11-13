<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Whether this process is serving in dev: `tinvar serve --dev` says so with
 * TINVAR_DEV=1, and the dev overlay, the renderer's raw messages and the
 * HMR allowances in the policy follow from it.
 *
 * Refused, loudly, everywhere but a machine that says it is a developer's.
 * The dev mode shows stacks, source paths and exception messages to whoever
 * loads a failing page, so a TINVAR_DEV left over in an env file copied to
 * a server must stop the process from booting, not quietly switch that on
 * for the world.
 *
 * And it is the one place every other development-only allowance asks
 * (ADR 0213): notDevelopment() is null only where TINVAR_ENV says local,
 * development or testing and nothing says production or staging. An
 * environment that says nothing -- a fresh VM, a container whose env file
 * did not load -- is read as one that may be production, never as a
 * developer's: refusing only a literal `production` let every server that
 * forgot to say so through.
 */
final class DevMode
{
    /** The variables any of which, saying `production`, rule dev mode out. */
    public const array PRODUCTION_MARKERS = ['TINVAR_ENV', 'APP_ENV', 'NODE_ENV'];

    /** The environment variable that says, explicitly, where this runs. */
    public const string ENV = 'TINVAR_ENV';

    /** The TINVAR_ENV values that say this is a developer's or a test's machine; nothing else does. */
    public const array DEVELOPMENT = ['local', 'development', 'testing'];

    /** What a server's TINVAR_ENV, APP_ENV or TINVAR_PROFILE says. */
    private const array SERVERS = ['production', 'staging'];

    /**
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when dev mode is asked for in production
     */
    public static function fromEnvironment(?array $env = null): bool
    {
        $env ??= getenv();

        return self::check(($env['TINVAR_DEV'] ?? '') === '1', $env);
    }

    /**
     * Whether dev mode is on, given whether it was asked for -- by the
     * framework's config, which reads TINVAR_DEV -- held to the production
     * markers in $env.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when dev mode is asked for in production
     */
    public static function check(bool $asked, ?array $env = null): bool
    {
        if (!$asked) {
            return false;
        }

        $env ??= getenv();
        $why = self::notDevelopment($env);
        if ($why !== null) {
            throw new \RuntimeException(
                "TINVAR_DEV=1 is refused: {$why}. Dev mode shows stacks, source paths and messages "
                . 'to anyone who loads a failing page, and runs only where TINVAR_ENV says local, development or testing. '
                . 'Set TINVAR_ENV=local where you develop, or unset TINVAR_DEV.',
            );
        }

        return true;
    }

    /**
     * Why $env may be a server's, or null where it says it is a developer's
     * or a test's machine: TINVAR_ENV local, development or testing, and no
     * production marker (productionMarker()), no TINVAR_ENV, APP_ENV or
     * TINVAR_PROFILE saying production or staging. The reason reads after
     * "refused:" or "where", as `APP_ENV=production` or `TINVAR_ENV is not
     * local, development or testing`. Every development-only allowance --
     * dev mode, the testing layer's fakes, a log for mail, plain http for
     * links -- asks this, so an environment that says nothing gets none.
     *
     * @param array<string, string|false>|null $env the environment; the process's own by default
     */
    public static function notDevelopment(?array $env = null): ?string
    {
        $env = array_filter($env ?? getenv(), \is_string(...));
        $marker = self::productionMarker($env);
        if ($marker !== null) {
            return "{$marker}=production";
        }
        foreach ([self::ENV, 'APP_ENV', BuildProfile::ENV] as $variable) {
            $value = strtolower(trim($env[$variable] ?? ''));
            if (\in_array($value, self::SERVERS, true)) {
                return "{$variable}={$value}";
            }
        }
        if (!\in_array(strtolower(trim($env[self::ENV] ?? '')), self::DEVELOPMENT, true)) {
            return self::ENV . ' is not ' . implode(', ', \array_slice(self::DEVELOPMENT, 0, -1)) . ' or ' . self::DEVELOPMENT[\count(self::DEVELOPMENT) - 1];
        }

        return null;
    }

    /**
     * Whether $env says it is a developer's or a test's machine, as
     * notDevelopment() reads it.
     *
     * @param array<string, string|false>|null $env the environment; the process's own by default
     */
    public static function isDevelopment(?array $env = null): bool
    {
        return self::notDevelopment($env) === null;
    }

    /**
     * The variable saying this is production, or null.
     *
     * @param array<string, string> $env
     */
    public static function productionMarker(array $env): ?string
    {
        foreach (self::PRODUCTION_MARKERS as $name) {
            if (strtolower(trim($env[$name] ?? '')) === 'production') {
                return $name;
            }
        }

        return null;
    }
}
