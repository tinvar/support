<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Authenticated encryption for what an application keeps at rest -- a
 * column a row holds encrypted -- under a key derived from the application
 * key for this one purpose, as Signer's are.
 *
 * Given the key an application resolves with AppKey::application(), which
 * is bound to the profile, a value encrypted in production is not one staging
 * can read, nor the other way round (ADR 0090): tenant data imported into
 * staging is re-encrypted with staging's by the framework's staging import.
 *
 * XChaCha20-Poly1305 from libsodium: a random 192-bit nonce per value, so
 * no counter has to be kept across workers, and a tag that refuses a value
 * changed by a single bit. The context -- `customers.notes`, say -- is
 * authenticated with it and not stored: a value moved to another column,
 * where it would be read as something else, does not decrypt there.
 */
final readonly class Encrypter
{
    public const string PURPOSE = 'column-encryption';

    /** What every value this writes starts with: the format, so another can follow. */
    public const string PREFIX = 'tv1.';

    private string $key;

    public function __construct(#[\SensitiveParameter] string $appKey)
    {
        if ($appKey === '') {
            throw new \InvalidArgumentException('An encryption key is required; set APP_KEY.');
        }
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('Encrypting needs PHP\'s sodium extension, which this PHP does not have.');
        }

        $this->key = hash_hmac('sha256', self::PURPOSE, $appKey, binary: true);
    }

    /** $plaintext sealed for $context, as printable ASCII a column of text holds. */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        self::assertContext($context);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);

        return self::PREFIX . Signer::encode($nonce . $sealed);
    }

    /**
     * What $ciphertext sealed for $context, or null: for a value this key did
     * not seal, sealed for another context, changed, or not one at all.
     */
    public function decrypt(string $ciphertext, string $context): ?string
    {
        self::assertContext($context);
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            return null;
        }

        $raw = Signer::decode(substr($ciphertext, \strlen(self::PREFIX)));
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === null || \strlen($raw) < $nonceBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return null;
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceBytes), $context, substr($raw, 0, $nonceBytes), $this->key);

        return \is_string($plaintext) ? $plaintext : null;
    }

    /**
     * A name for the key this encrypts under, which says nothing about it:
     * two encrypters with one id hold one key. The staging import refuses
     * to re-encrypt under the key it decrypts with.
     */
    public function keyId(): string
    {
        return substr(Signer::encode(hash_hmac('sha256', 'key-id', $this->key, binary: true)), 0, 16);
    }

    private static function assertContext(string $context): void
    {
        if ($context === '') {
            throw new \InvalidArgumentException('An encryption context is required: it is what keeps a value to its column.');
        }
    }
}
