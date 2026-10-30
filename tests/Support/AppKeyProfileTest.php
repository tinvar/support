<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\AppKeyProfile;

use Tinvar\Support\AppKey;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;

/**
 * The application key bound to the profile a server serves (ADR 0090):
 * staging and production, given one key, sign with two.
 */
const KEY = 'an-application-key-of-at-least-thirty-two-bytes';

it('leaves production\'s key as it is, so no token in flight is refused', function () {
    expect(AppKey::forProfile(KEY, 'production'))->toBe(KEY);
});

it('gives every other profile a key of its own', function () {
    $staging = AppKey::forProfile(KEY, 'staging');
    $test = AppKey::forProfile(KEY, 'test');

    expect($staging)->not->toBe(KEY)
        ->and($test)->not->toBe(KEY)
        ->and($test)->not->toBe($staging)
        ->and(AppKey::forProfile(KEY, 'staging'))->toBe($staging)
        ->and(AppKey::forProfile(KEY . 'x', 'staging'))->not->toBe($staging)
        ->and($staging)->toMatch('/^[\x21-\x7e]+\z/')
        ->and(\strlen($staging))->toBeGreaterThanOrEqual(AppKey::MIN_LENGTH)
        ->and($staging)->not->toContain(KEY);
});

it('refuses a profile there is none of', function () {
    AppKey::forProfile(KEY, 'qa');
})->throws(\InvalidArgumentException::class, 'is not a profile');

it('signs nothing in one that verifies in the other', function (string $purpose) {
    $production = new Signer(AppKey::forProfile(KEY, 'production'), $purpose);
    $staging = new Signer(AppKey::forProfile(KEY, 'staging'), $purpose);

    expect($production->verify('payload', $staging->sign('payload')))->toBeFalse()
        ->and($staging->verify('payload', $production->sign('payload')))->toBeFalse();
})->with(['session-cookie', 'route-token', 'fragment-token', 'csrf-token', 'signed-url', 'upload-ticket', 'email-link']);

it('reads the profile from the environment it is given, before any key is made', function () {
    $directory = PrivateDirectory::create('appkey-profile');
    $file = "{$directory}/key";
    putenv('TINVAR_TEST_PROFILE_KEY=' . KEY);
    try {
        expect(AppKey::application('TINVAR_TEST_PROFILE_KEY', $file, []))->toBe(KEY)
            ->and(AppKey::application('TINVAR_TEST_PROFILE_KEY', $file, ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging']))
            ->toBe(AppKey::forProfile(KEY, 'staging'));

        // A refused profile stops before a key file is generated.
        putenv('TINVAR_TEST_PROFILE_KEY');
        expect(fn () => AppKey::application('TINVAR_TEST_PROFILE_KEY', $file, ['TINVAR_PROFILE' => 'staging']))
            ->toThrow(\RuntimeException::class, 'does not say staging')
            ->and(is_file($file))->toBeFalse();
    } finally {
        putenv('TINVAR_TEST_PROFILE_KEY');
        PrivateDirectory::remove($directory);
    }
});

// The probe token and the hub's keys set in the environment, bound as the
// key is: what staging lets in is not what production's scrapers send.
it('binds a secret handed out as it is, once, and refuses one bound to another profile', function () {
    $staging = AppKey::bound(KEY, 'staging');

    expect(AppKey::bound(KEY, 'production'))->toBe(KEY)
        ->and($staging)->toBe(AppKey::forProfile(KEY, 'staging'))
        // Handed on in the variable it was read from, it is not bound twice.
        ->and(AppKey::bound($staging, 'staging'))->toBe($staging)
        ->and(fn () => AppKey::bound($staging, 'production'))->toThrow(\InvalidArgumentException::class, 'bound to the staging profile')
        ->and(fn () => AppKey::bound(AppKey::forProfile(KEY, 'test'), 'staging'))->toThrow(\InvalidArgumentException::class, 'bound to the test profile');
});

it('resolves the probe token bound to the profile the environment names', function () {
    $directory = PrivateDirectory::create('appkey-secret');
    putenv('TINVAR_TEST_PROBE=' . KEY);
    try {
        expect(AppKey::secret('TINVAR_TEST_PROBE', "{$directory}/token", []))->toBe(KEY)
            ->and(AppKey::secret('TINVAR_TEST_PROBE', "{$directory}/token", ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging']))
            ->toBe(AppKey::forProfile(KEY, 'staging'));
    } finally {
        putenv('TINVAR_TEST_PROBE');
    }
});
