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
    if (is_file("{$directory}/rsc/profile.json")) {
        unlink("{$directory}/rsc/profile.json");
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
    BuildProfile::fromEnvironment(['TINVAR_PROFILE' => 'staging']);
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
})->with(['production', 'test']);

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
    'unknown profile' => ['{"format":1,"profile":"staging"}'],
])->throws(\RuntimeException::class, 'does not say which profile');

it('serves a build made for its own profile', function (string $profile) {
    $directory = build(json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
    try {
        BuildProfile::assertBuild($profile, $directory);
        expect(true)->toBeTrue();
    } finally {
        removeBuild($directory);
    }
})->with(['production', 'test']);

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
