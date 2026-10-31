<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * The spec's socket format.
 *
 * Decoding is always the pure-PHP MessagePack. "PHP decodes MessagePack to
 * arrays and scalars only, never PHP objects", and ext-msgpack's unpacker,
 * in its default mode, rebuilds PHP objects from its own encoding of them:
 * a renderer running compromised npm code could have chosen what the trusted
 * process instantiated. Checking the result afterwards would be too late --
 * construction is where the harm is.
 *
 * ext-msgpack still encodes when loaded, which is the hot direction. Unlike
 * the pure-PHP encoder it would serialize any object, private properties and
 * all, so a frame is checked for objects first: a loader result that skipped
 * the Data encoder fails here the same way with or without the extension.
 */
final class MessagePackCodec implements FrameCodec
{
    use LengthPrefixed;

    private static ?bool $native = null;

    public static function hasNativeExtension(): bool
    {
        return self::$native ??= \extension_loaded('msgpack');
    }

    public function name(): string
    {
        return self::hasNativeExtension() ? 'msgpack (ext encode)' : 'msgpack (php)';
    }

    public function encodeFrame(array $frame): string
    {
        if (!self::hasNativeExtension()) {
            return $this->frame(MessagePack::encode($frame));
        }

        // A Bin is the pure encoder's, which writes it as MessagePack bin:
        // ext-msgpack would write the object, so a frame holding one -- a
        // render's non-UTF-8 chunk -- goes the pure way.
        if (self::assertPlain($frame)) {
            return $this->frame(MessagePack::encode($frame));
        }

        return $this->frame(msgpack_pack($frame));
    }

    public function decodeFrame(string $buffer, int &$offset): ?array
    {
        $body = $this->unframe($buffer, $offset);
        if ($body === null) {
            return null;
        }

        $value = MessagePack::decode($body);
        if (!\is_array($value)) {
            throw new CodecException('Frame body must decode to a map.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Arrays and scalars only, and Bin, as the pure-PHP encoder accepts.
     *
     * @param array<array-key, mixed> $value
     * @return bool whether it holds a Bin anywhere, which only the pure-PHP encoder writes as bin
     */
    public static function assertPlain(array $value, int $depth = 0): bool
    {
        if ($depth > MessagePack::MAX_DEPTH) {
            throw new CodecException('Frame nests deeper than ' . MessagePack::MAX_DEPTH . ' levels.');
        }

        $bin = false;
        foreach ($value as $item) {
            if (\is_array($item)) {
                $bin = self::assertPlain($item, $depth + 1) || $bin;
            } elseif ($item instanceof Bin) {
                $bin = true;
            } elseif (\is_object($item) || \is_resource($item)) {
                throw new CodecException('Cannot encode ' . get_debug_type($item) . '; only arrays and scalars cross the socket.');
            }
        }

        return $bin;
    }
}
