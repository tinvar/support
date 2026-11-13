<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Which profile a server runs, and which one its build was made for: the
 * spec's build profile, as far as a language that is not built can have one
 * (ADR 0066).
 *
 * Three profiles. `production`, the default, has no test tooling at all.
 * `staging` has none either -- it is production's build made for another
 * environment -- but it is not production: its application key is bound to
 * it (AppKey::forProfile), so nothing one signs or encrypts is believed by
 * the other, and `#[Scenario]` users may be seeded into it for `tinvar
 * bench` (ADR 0090). `test` is what a browser test run serves: the
 * endpoint that seeds, signs in and moves the clock (tinvar/testing's
 * BrowserTests) is there, and only there. A profile is two halves that
 * have to agree, and either one alone is refused:
 *
 *   - The server's, TINVAR_PROFILE. `test` is refused beside any production
 *     marker, and also where nothing says this is not production: TINVAR_ENV
 *     must say local, development or testing, as for `migrate:fresh` --
 *     unset counts as production. And refused in dev mode, which serves no
 *     build to agree with.
 *     `staging` needs TINVAR_ENV=staging, and TINVAR_ENV=staging needs it:
 *     a staging deployment that forgot its profile would sign with the
 *     key production's tokens are checked against, were the two keys the
 *     same.
 *   - The build's, written into it by `tinvar build --profile=...` (the
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

    public const string STAGING = 'staging';

    public const string TEST = 'test';

    public const array PROFILES = [self::PRODUCTION, self::STAGING, self::TEST];

    /** The TINVAR_ENV a staging server says, and the only one it may. */
    public const string STAGING_ENV = 'staging';

    /** The TINVAR_ENV values that say this is not production; nothing else does. */
    public const array NON_PRODUCTION = DevMode::DEVELOPMENT;

    /** The build's own record of its profile, in the rsc environment's output. */
    public const string BUILD_FILE = 'rsc/profile.json';

    /** The client manifest a build's id is the hash of: what a browser holds code from. */
    public const string CLIENT_MANIFEST = 'rsc/__vite_rsc_assets_manifest.js';

    /**
     * The profile this process serves.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when the profile is unknown, or test where it is refused
     */
    public static function fromEnvironment(?array $env = null): string
    {
        $env ??= array_filter(getenv(), \is_string(...));

        return self::resolve($env[self::ENV] ?? '', ($env['TINVAR_DEV'] ?? '') === '1', $env);
    }

    /**
     * The profile $given names -- as the framework's config read it from
     * TINVAR_PROFILE -- held to the rest of $env as fromEnvironment() holds
     * it, with $dev for whether dev mode was asked for.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when the profile is unknown, or test where it is refused
     */
    public static function resolve(string $given, bool $dev, ?array $env = null): string
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $profile = strtolower(trim($given));
        $profile = $profile === '' ? self::PRODUCTION : $profile;
        if (!\in_array($profile, self::PROFILES, true)) {
            throw new \RuntimeException(self::ENV . "='{$profile}' is not a profile: it is production, staging or test.");
        }

        // Either of the application's words for where it is: a staging
        // deployment that set APP_ENV alone and forgot its profile would
        // sign with production's key as surely as one that set TINVAR_ENV.
        foreach (['TINVAR_ENV', 'APP_ENV'] as $marker) {
            if ($profile === self::PRODUCTION && strtolower(trim($env[$marker] ?? '')) === self::STAGING_ENV) {
                throw new \RuntimeException(
                    "{$marker}=staging, and the profile is {$profile}. Staging is served under " . self::ENV . '=staging, '
                    . 'whose key is its own: set it, with a build made by `tinvar build --profile=staging`.',
                );
            }
        }

        if ($profile === self::STAGING) {
            self::assertStagingAllowed($env);
        }

        if ($profile !== self::TEST) {
            if (($env[self::RUN_ENV] ?? '') !== '') {
                throw new \RuntimeException(
                    self::RUN_ENV . " is set, and the profile is {$profile}. It names"
                    . ' a browser test run, and only a '
                    . 'server started for one, with ' . self::ENV . '=test, reads it. Unset it.',
                );
            }

            return $profile;
        }

        self::assertTestAllowed([...$env, 'TINVAR_DEV' => $dev ? '1' : '']);

        return $profile;
    }

    /**
     * Whether a server under $profile may hold `#[Scenario]` users -- seeded
     * accounts whose passwords a load test knows. Never production's.
     */
    public static function allowsScenarios(string $profile): bool
    {
        return $profile === self::STAGING || $profile === self::TEST;
    }

    /**
     * Refuses the staging profile unless TINVAR_ENV says staging and nothing
     * says production.
     *
     * NODE_ENV is not read: a staging server is production's build, and
     * whatever starts it may well say NODE_ENV=production for the renderer.
     * TINVAR_ENV and APP_ENV are this application's own word for where it is.
     *
     * @param array<string, string> $env
     * @throws \RuntimeException
     */
    public static function assertStagingAllowed(array $env): void
    {
        foreach (['TINVAR_ENV', 'APP_ENV'] as $marker) {
            if (strtolower(trim($env[$marker] ?? '')) === 'production') {
                throw new \RuntimeException(self::ENV . "=staging is refused: {$marker}=production.");
            }
        }

        if (strtolower(trim($env['TINVAR_ENV'] ?? '')) !== self::STAGING_ENV) {
            throw new \RuntimeException(
                self::ENV . '=staging is refused: TINVAR_ENV does not say staging. Set TINVAR_ENV=staging where '
                . 'staging runs, and nowhere else.',
            );
        }

        if (($env['TINVAR_DEV'] ?? '') === '1') {
            throw new \RuntimeException(
                self::ENV . '=staging is refused in dev mode, which renders from source: staging serves a build made '
                . 'with `tinvar build --profile=staging`.',
            );
        }
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
     * build says otherwise.
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
     * The id `php tinvar serve` names $directory's build by, as TINVAR_BUILD: the
     * first 16 hex digits of its client manifest's SHA-256 -- the spec's
     * "client manifest hash is the version" -- and, for a build made for
     * any profile but production, that profile in front of it
     * (`test-…`, `staging-…`).
     *
     * So a test, a staging and a production build of the same source are
     * three builds, not one, wherever ids are compared: a build history, a
     * route or fragment token, X-Version. A production build's id is the
     * hash alone, as it always was, so a history recorded before keeps
     * naming the same builds.
     *
     * @throws \RuntimeException for a build with no client manifest, or a
     *         record of its profile that is not one the plugin writes
     */
    public static function buildId(string $directory): string
    {
        $profile = self::ofBuild($directory);
        $manifest = rtrim($directory, '/') . '/' . self::CLIENT_MANIFEST;
        $hash = is_file($manifest) && !is_link($manifest) ? hash_file('sha256', $manifest) : false;
        if ($hash === false) {
            throw new \RuntimeException("{$manifest} is missing: {$directory} is not a finished build. Build it again.");
        }

        return ($profile === self::PRODUCTION ? '' : "{$profile}-") . substr($hash, 0, 16);
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
            ($built === self::PRODUCTION
                ? "This server's profile is {$profile}, and the build in {$directory} was made for production. "
                : "The build in {$directory} was made with --profile={$built}, and this server's profile is {$profile}. ")
            . 'A build is served only under the profile it was made for: `tinvar build --profile=' . $profile . '`.',
        );
    }

    /**
     * The application's build directory: TINVAR_BUILD_DIR, or `dist` in
     * $app.
     *
     * TINVAR_BUILD_DIR must be absolute: the server, the renderers and the
     * Caddyfile each run in a directory of their own, and a relative one
     * named a different build to each -- the profile checked in one, the
     * renderers loading another.
     *
     * @param array<string, string>|null $env
     * @throws \RuntimeException for a relative TINVAR_BUILD_DIR
     */
    public static function buildDirectory(string $app, ?array $env = null): string
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $configured = $env[self::BUILD_DIR_ENV] ?? '';
        if ($configured !== '' && !str_starts_with($configured, '/')) {
            throw new \RuntimeException(self::BUILD_DIR_ENV . "='{$configured}' is not an absolute path; name the build by one.");
        }

        return rtrim($configured !== '' ? $configured : rtrim($app, '/') . '/dist', '/');
    }
}
