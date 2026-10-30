<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\BuildProfile;

use Tinvar\Support\BuildProfile;
use Tinvar\Support\PrivateDirectory;

/** A build directory whose profile record says $record, or holds none. */
function build(?string $record): string
{
    $directory = PrivateDirectory::create('profile');
    mkdir("{$directory}/rsc");
    if ($record !== null) {
        file_put_contents("{$directory}/rsc/profile.json", $record);
    }

    return $directory;
}

function removeBuild(string $directory): void
{
    foreach (['profile.json', '__vite_rsc_assets_manifest.js'] as $file) {
        if (is_file("{$directory}/rsc/{$file}")) {
            unlink("{$directory}/rsc/{$file}");
        }
    }
    rmdir("{$directory}/rsc");
    PrivateDirectory::remove($directory);
}

it('is production when nothing names a profile', function () {
    expect(BuildProfile::fromEnvironment([]))->toBe('production')
        ->and(BuildProfile::fromEnvironment(['TINVAR_PROFILE' => '']))->toBe('production')
        ->and(BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'production']))->toBe('production');
});

it('is test where the environment says it is not production', function (string $env) {
    expect(BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => $env]))->toBe('test');
})->with(['testing', 'local', 'development']);

it('refuses a profile it does not know', function () {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'qa']);
})->throws(\RuntimeException::class, 'is not a profile');

// Unset counts as production, as it does for migrate:fresh.
it('refuses the test profile where nothing says this is not production', function (array $env) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'test', ...$env]);
})->with([
    'unset' => [[]],
    'empty' => [['TINVAR_ENV' => '']],
    'staging' => [['TINVAR_ENV' => 'staging']],
])->throws(\RuntimeException::class, 'nothing says this is not production');

it('refuses the test profile beside any production marker', function (string $marker) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', $marker => 'Production']);
})->with(['APP_ENV', 'NODE_ENV'])->throws(\RuntimeException::class, '=production');

it('refuses the test profile where TINVAR_ENV itself says production', function () {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'production']);
})->throws(\RuntimeException::class, 'TINVAR_ENV=production');

it('refuses the test profile in dev mode, which serves no build', function () {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'local', 'TINVAR_DEV' => '1']);
})->throws(\RuntimeException::class, 'dev mode');

it('refuses a test run named without the test profile', function () {
    BuildProfile::fromEnvironment(['TINVAR_TEST_RUN' => '/tmp/somewhere', 'TINVAR_ENV' => 'testing']);
})->throws(\RuntimeException::class, 'TINVAR_TEST_RUN is set');

it('reads a build with no record as a production build', function () {
    $directory = build(null);
    try {
        expect(BuildProfile::ofBuild($directory))->toBe('production');
    } finally {
        removeBuild($directory);
    }
});

it('reads the profile a build records', function (string $profile) {
    $directory = build(json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
    try {
        expect(BuildProfile::ofBuild($directory))->toBe($profile);
    } finally {
        removeBuild($directory);
    }
})->with(['production', 'staging', 'test']);

it('refuses a record it cannot read', function (string $record) {
    $directory = build($record);
    try {
        BuildProfile::ofBuild($directory);
    } finally {
        removeBuild($directory);
    }
})->with([
    'not json' => ['test'],
    'no format' => ['{"profile":"test"}'],
    'unknown profile' => ['{"format":1,"profile":"qa"}'],
])->throws(\RuntimeException::class, 'does not say which profile');

it('serves a build made for its own profile', function (string $profile) {
    $directory = build(json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
    try {
        BuildProfile::assertBuild($profile, $directory);
        expect(true)->toBeTrue();
    } finally {
        removeBuild($directory);
    }
})->with(['production', 'staging', 'test']);

it('refuses a test build without the test profile', function () {
    $directory = build('{"format":1,"profile":"test"}');
    try {
        BuildProfile::assertBuild('production', $directory);
    } finally {
        removeBuild($directory);
    }
})->throws(\RuntimeException::class, 'was made with --profile=test');

it('refuses the test profile with a production build, or one that records nothing', function (?string $record) {
    $directory = build($record);
    try {
        BuildProfile::assertBuild('test', $directory);
    } finally {
        removeBuild($directory);
    }
})->with([
    'production' => ['{"format":1,"profile":"production"}'],
    'no record' => [null],
])->throws(\RuntimeException::class, 'was made for production');

// Each process that reads the build runs in a directory of its own.
it('refuses a relative TINVAR_BUILD_DIR', function () {
    BuildProfile::buildDirectory('/srv/app', ['TINVAR_BUILD_DIR' => 'dist-test']);
})->throws(\RuntimeException::class, 'not an absolute path');

it('finds the build in dist unless TINVAR_BUILD_DIR names one', function () {
    expect(BuildProfile::buildDirectory('/srv/app/', []))->toBe('/srv/app/dist')
        ->and(BuildProfile::buildDirectory('/srv/app', ['TINVAR_BUILD_DIR' => '/srv/app/dist-test/']))->toBe('/srv/app/dist-test');
});

// Staging (ADR 0090): production's build, made for an environment of its own.

it('is staging where TINVAR_ENV says staging', function () {
    expect(BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'Staging']))->toBe('staging')
        ->and(BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', 'NODE_ENV' => 'production']))->toBe('staging');
});

it('refuses the staging profile where TINVAR_ENV does not say staging', function (array $env) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', ...$env]);
})->with([
    'unset' => [[]],
    'local' => [['TINVAR_ENV' => 'local']],
    'testing' => [['TINVAR_ENV' => 'testing']],
])->throws(\RuntimeException::class, 'does not say staging');

it('refuses the staging profile where TINVAR_ENV or APP_ENV says production', function (array $env) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', ...$env]);
})->with([
    'TINVAR_ENV' => [['TINVAR_ENV' => 'production']],
    'APP_ENV' => [['TINVAR_ENV' => 'staging', 'APP_ENV' => 'production']],
])->throws(\RuntimeException::class, '=production');

it('refuses the staging profile in dev mode', function () {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', 'TINVAR_DEV' => '1']);
})->throws(\RuntimeException::class, 'dev mode');

// A staging deployment that forgot its profile would sign with production's key derivation.
it('refuses TINVAR_ENV=staging under the production profile', function (string $profile) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => $profile, 'TINVAR_ENV' => 'staging']);
})->with(['unset' => [''], 'production' => ['production']])->throws(\RuntimeException::class, 'TINVAR_ENV=staging, and the profile is production');

it('refuses APP_ENV=staging under the production profile', function (string $profile) {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => $profile, 'APP_ENV' => 'staging']);
})->with(['unset' => [''], 'production' => ['production']])->throws(\RuntimeException::class, 'APP_ENV=staging, and the profile is production');

it('refuses a test run named under the staging profile', function () {
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', 'TINVAR_TEST_RUN' => '/tmp/run']);
})->throws(\RuntimeException::class, 'TINVAR_TEST_RUN is set, and the profile is staging');

it('serves a build under the profile it was made for alone', function (string $built, string $serving) {
    $directory = build(json_encode(['format' => 1, 'profile' => $built], JSON_THROW_ON_ERROR));
    try {
        BuildProfile::assertBuild($serving, $directory);
    } finally {
        removeBuild($directory);
    }
})->with([
    'staging build, production server' => ['staging', 'production'],
    'production build, staging server' => ['production', 'staging'],
    'staging build, test server' => ['staging', 'test'],
    'test build, staging server' => ['test', 'staging'],
])->throws(\RuntimeException::class, 'served only under the profile it was made for');

it('lets scenarios be seeded under staging and test alone', function () {
    expect(BuildProfile::allowsScenarios('production'))->toBeFalse()
        ->and(BuildProfile::allowsScenarios('staging'))->toBeTrue()
        ->and(BuildProfile::allowsScenarios('test'))->toBeTrue()
        ->and(BuildProfile::allowsScenarios('qa'))->toBeFalse();
});

// ADR 0066: a test, a staging and a production build of one source are
// three builds, and a production build keeps the id it always had.
it('names a build by its client manifest and, but for production, its profile', function () {
    $manifest = "export default { clientReferenceDeps: {} };\n";
    $hash = substr(hash('sha256', $manifest), 0, 16);
    $ids = [];
    foreach (['production' => null, 'staging' => 'staging', 'test' => 'test'] as $name => $profile) {
        $directory = build($profile === null ? null : json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
        file_put_contents("{$directory}/rsc/__vite_rsc_assets_manifest.js", $manifest);
        try {
            $ids[$name] = BuildProfile::buildId($directory);
        } finally {
            removeBuild($directory);
        }
    }

    expect($ids)->toBe(['production' => $hash, 'staging' => "staging-{$hash}", 'test' => "test-{$hash}"]);
});

it('refuses to name a build with no client manifest', function () {
    $directory = build(null);
    try {
        BuildProfile::buildId($directory);
    } finally {
        removeBuild($directory);
    }
})->throws(\RuntimeException::class, 'is not a finished build');
