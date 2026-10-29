<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\Encrypter;

use Tinvar\Support\AppKey;
use Tinvar\Support\Encrypter;
use Tinvar\Support\Signer;

const KEY = 'an-application-key-of-at-least-thirty-two-bytes';

it('reads back what it sealed, for the context it sealed it for', function () {
    $encrypter = new Encrypter(KEY);
    $sealed = $encrypter->encrypt('4111 1111 1111 1111', 'customers.card');

    expect($sealed)->toStartWith(Encrypter::PREFIX)
        ->and($sealed)->not->toContain('4111')
        ->and($encrypter->decrypt($sealed, 'customers.card'))->toBe('4111 1111 1111 1111')
        ->and($encrypter->encrypt('', 'x'))->not->toBe($encrypter->encrypt('', 'x'));
});

it('refuses a value moved to another column', function () {
    $encrypter = new Encrypter(KEY);

    expect($encrypter->decrypt($encrypter->encrypt('secret', 'customers.card'), 'customers.notes'))->toBeNull();
});

it('refuses a value changed by a bit, cut short, or not one at all', function () {
    $encrypter = new Encrypter(KEY);
    $sealed = $encrypter->encrypt('secret', 'c');
    $raw = (string) Signer::decode(substr($sealed, \strlen(Encrypter::PREFIX)));
    $flipped = Encrypter::PREFIX . Signer::encode(substr($raw, 0, -1) . \chr(\ord($raw[-1]) ^ 1));

    expect($encrypter->decrypt($flipped, 'c'))->toBeNull()
        ->and($encrypter->decrypt(substr($sealed, 0, 30), 'c'))->toBeNull()
        ->and($encrypter->decrypt('secret', 'c'))->toBeNull()
        ->and($encrypter->decrypt(Encrypter::PREFIX . '!!', 'c'))->toBeNull();
});

// ADR 0090: the one key, bound to two profiles, is two keys.
it('does not read in staging what production sealed, nor the other way round', function () {
    $production = new Encrypter(AppKey::forProfile(KEY, 'production'));
    $staging = new Encrypter(AppKey::forProfile(KEY, 'staging'));

    expect($staging->decrypt($production->encrypt('secret', 'c'), 'c'))->toBeNull()
        ->and($production->decrypt($staging->encrypt('secret', 'c'), 'c'))->toBeNull()
        ->and($production->keyId())->not->toBe($staging->keyId())
        ->and(new Encrypter(KEY)->keyId())->toBe($production->keyId());
});

it('refuses an empty key or context', function (\Closure $call) {
    $call();
})->with([
    'key' => [static fn () => new Encrypter('')],
    'context' => [static fn () => new Encrypter(KEY)->encrypt('x', '')],
])->throws(\InvalidArgumentException::class);
