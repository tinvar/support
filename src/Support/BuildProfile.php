<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Which profile a server runs, and which one its build was made for: the
 * spec's build profile, as far as a language that is not built can have one
 * (ADR 0066).
 *
 * Two profiles. `production`, the default, has no test tooling at all.
 * `test` is what a browser test run serves: the endpoint that seeds, signs
 * in and moves the clock (tinvar/testing's BrowserTests) is there, and only
 * there. A profile is two halves that have to agree, and either one alone
 * is refused:
 *
 *   - The server's, TINVAR_PROFILE. `test` is refused beside any production
 *     marker, and also where nothing says this is not production: TINVAR_ENV
 *     must say local, development or testing, as for `migrate:fresh` --
 *     unset counts as production. And refused in dev mode, which serves no
 *     build to agree with.
 *   - The build's, written into it by `tinvar build --profile=test` (the
 *     renderer's buildProfile() Vite plugin), in `rsc/profile.json`. A build
 *     without one is a production build.
 *
 * So the endpoint needs the environment changed AND a build made for it:
 * a server whose build was made for production -- an image's, read-only --
 * cannot be given the endpoint by an environment variable, and a test build
 * copied to a server that does not say test stops it from starting.
 *
 * TINVAR_TEST_RUN, which names a browser test run's private directory, is
 * refused under any profile but test: it is a test runner's setting found
 * where no test runs.
 */
final class BuildProfile
{
    /** The server's profile. */
    public const string ENV = 'TINVAR_PROFILE';

    /** A browser test run's private directory: its token's hash and its clock. */
    public const string RUN_ENV = 'TINVAR_TEST_RUN';

    /** Where an application's build is, when not `dist/` in the application. */
    public const string BUILD_DIR_ENV = 'TINVAR_BUILD_DIR';

    public const string PRODUCTION = 'production';

    public const string TEST = 'test';

    public const array PROFILES = [self::PRODUCTION, self::TEST];

    /** The TINVAR_ENV values that say this is not production; nothing else does. */
    public const array NON_PRODUCTION = ['local', 'development', 'testing'];

    /** The build's own record of its profile, in the rsc environment's output. */
    public const string BUILD_FILE = 'rsc/profile.json';

    /**
     * The profile this process serves.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when the profile is unknown, or test where it is refused
     */
    public static function fromEnvironment(?array $env = null): string
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $profile = strtolower(trim($env[self::ENV] ?? ''));
        $profile = $profile === '' ? self::PRODUCTION : $profile;
        if (!\in_array($profile, self::PROFILES, true)) {
            throw new \RuntimeException(self::ENV . "='{$profile}' is not a profile: it is production or test.");
        }

        if ($profile === self::PRODUCTION) {
            if (($env[self::RUN_ENV] ?? '') !== '') {
                throw new \RuntimeException(
                    self::RUN_ENV . ' is set, and the profile is production. It names a browser test run, and only a '
                    . 'server started for one, with ' . self::ENV . '=test, reads it. Unset it.',
                );
            }

            return $profile;
        }

        self::assertTestAllowed($env);

        return $profile;
    }

    /**
     * Refuses the test profile here unless the environment says this is not
     * production and nothing says it is.
     *
     * @param array<string, string> $env
     * @throws \RuntimeException
     */
    public static function assertTestAllowed(array $env): void
    {
        $marker = DevMode::productionMarker($env);
        if ($marker !== null) {
            throw new \RuntimeException(
                self::ENV . "=test is refused: {$marker}=production. The test profile serves an endpoint that signs "
                . 'anyone in and moves the clock, and never runs in production.',
            );
        }

        if (!\in_array(strtolower(trim($env['TINVAR_ENV'] ?? '')), self::NON_PRODUCTION, true)) {
            throw new \RuntimeException(
                self::ENV . '=test is refused: nothing says this is not production. Set TINVAR_ENV=testing (or local, '
                . 'or development) where browser tests run.',
            );
        }

        if (($env['TINVAR_DEV'] ?? '') === '1') {
            throw new \RuntimeException(
                self::ENV . '=test is refused in dev mode, which renders from source: the test profile serves a build '
                . 'made with `tinvar build --profile=test`.',
            );
        }
    }

    /**
     * The profile $directory's build was made for: production unless the
     * build says test.
     *
     * @throws \RuntimeException for a record that is not one the plugin writes
     */
    public static function ofBuild(string $directory): string
    {
        $file = rtrim($directory, '/') . '/' . self::BUILD_FILE;
        clearstatcache(true, $file);
        if (!file_exists($file) && !is_link($file)) {
            return self::PRODUCTION;
        }

        $read = is_file($file) && !is_link($file) ? file_get_contents($file) : false;
        $decoded = \is_string($read) ? json_decode($read, true) : null;
        $profile = \is_array($decoded) && ($decoded['format'] ?? null) === 1 ? ($decoded['profile'] ?? null) : null;
        if (!\is_string($profile) || !\in_array($profile, self::PROFILES, true)) {
            throw new \RuntimeException("{$file} does not say which profile the build was made for. Build it again.");
        }

        return $profile;
    }

    /**
     * Refuses to serve a build made for another profile than $profile: a
     * test build without the test profile, and the test profile with a
     * build made for production.
     *
     * @throws \RuntimeException
     */
    public static function assertBuild(string $profile, string $directory): void
    {
        $built = self::ofBuild($directory);
        if ($built === $profile) {
            return;
        }

        throw new \RuntimeException(
            $built === self::TEST
                ? "The build in {$directory} was made with --profile=test, and this server's profile is {$profile}. "
                    . 'A test build is served only under ' . self::ENV . '=test; build it again with `tinvar build`.'
                : "This server's profile is test, and the build in {$directory} was made for production. "
                    . 'The test profile serves a build made for it: `tinvar build --profile=test`.',
        );
    }

    /**
     * The application's build directory: TINVAR_BUILD_DIR, or `dist` in
     * $app.
     *
     * @param array<string, string>|null $env
     */
    public static function buildDirectory(string $app, ?array $env = null): string
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $configured = $env[self::BUILD_DIR_ENV] ?? '';

        return rtrim($configured !== '' ? $configured : rtrim($app, '/') . '/dist', '/');
    }
}
