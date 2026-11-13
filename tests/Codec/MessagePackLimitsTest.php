<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codec\Limits;

use Tinvar\Codec\CodecException;
use Tinvar\Codec\MessagePack;
use Tinvar\Codec\MessagePackCodec;

// What the renderer sends is decoded by the trusted process, and the renderer
// runs third-party npm code. A frame under the size cap still took PHP past
// its memory limit -- a fatal error no catch can answer -- so the decoder
// refuses a frame before it builds it.

it('refuses nesting deeper than any frame has', function () {
    $bomb = str_repeat("\x91", MessagePack::MAX_DEPTH + 1) . "\xc0";

    expect(fn () => MessagePack::decode($bomb))->toThrow(CodecException::class, 'nests deeper');
});

it('decodes nesting up to the limit', function () {
    $deep = str_repeat("\x91", MessagePack::MAX_DEPTH - 1) . "\xc0";

    $value = MessagePack::decode($deep);
    for ($i = 1; $i < MessagePack::MAX_DEPTH; $i++) {
        $value = $value[0];
    }

    expect($value)->toBeNull();
});

it('refuses more elements than a frame may hold, before building them', function () {
    $many = "\xdd" . pack('N', MessagePack::MAX_ELEMENTS + 1) . str_repeat("\xc0", MessagePack::MAX_ELEMENTS + 1);

    expect(fn () => MessagePack::decode($many))->toThrow(CodecException::class, 'more than');
});

it('counts the budget across the whole frame, not per container', function () {
    $half = intdiv(MessagePack::MAX_ELEMENTS, 2) + 1;
    $list = "\xdd" . pack('N', $half) . str_repeat("\xc0", $half);

    expect(fn () => MessagePack::decode("\x92" . $list . $list))->toThrow(CodecException::class, 'more than');
});

it('refuses a count the remaining bytes could not hold', function () {
    expect(fn () => MessagePack::decode("\xdd\xff\xff\xff\xff\xc0"))->toThrow(CodecException::class);
});

/** A map of $keys to nil, as MessagePack writes it. */
function mapOf(array $keys): string
{
    $bytes = "\xdf" . pack('N', \count($keys));
    foreach ($keys as $key) {
        $bytes .= MessagePack::encode($key) . "\xc0";
    }

    return $bytes;
}

/** 2^$pairs distinct strings PHP hashes alike: "Ez" and "FY" are one DJBX33A step apart. */
function collidingStrings(int $pairs): array
{
    $keys = [''];
    for ($i = 0; $i < $pairs; $i++) {
        $keys = [...array_map(static fn (string $k): string => $k . 'Ez', $keys), ...array_map(static fn (string $k): string => $k . 'FY', $keys)];
    }

    return $keys;
}

describe('a map whose keys PHP would hash into one bucket (ADR 0220)', function () {
    // A PHP array inserts in time proportional to the keys already in the
    // key's bucket, so keys that all share one cost quadratic time: 65,536
    // integers 2^20 apart, a 655 KB frame, took 1.8 s to decode.

    it('refuses integers that share their low bits', function () {
        $keys = array_map(static fn (int $i): int => $i << 20, range(1, 4096));

        expect(fn () => MessagePack::decode(mapOf($keys)))->toThrow(CodecException::class, 'collide');
    });

    it('refuses strings that hash alike', function () {
        expect(fn () => MessagePack::decode(mapOf(collidingStrings(12))))->toThrow(CodecException::class, 'collide');
    });

    it('refuses digit strings, which PHP keys as the integers they spell', function () {
        $keys = array_map(static fn (int $i): string => (string) ($i << 20), range(1, 4096));

        expect(fn () => MessagePack::decode(mapOf($keys)))->toThrow(CodecException::class, 'collide');
    });

    it('decodes large maps of ordinary keys', function (array $keys) {
        $decoded = MessagePack::decode(mapOf($keys));

        expect(array_keys($decoded))->toBe(array_map(static fn (int|string $k): int|string => \is_string($k) && (string) (int) $k === $k ? (int) $k : $k, $keys));
    })->with([
        'names' => [array_map(static fn (int $i): string => "field_{$i}", range(1, 20_000))],
        'ids' => [range(1_000_000, 1_020_000)],
        'sparse ids' => [array_map(static fn (int $i): int => 7 + $i * 37, range(1, 20_000))],
        'uuids' => [array_map(static fn (int $i): string => sprintf('%08x-0000-4000-8000-%012x', crc32((string) $i), $i), range(1, 5_000))],
        'timestamps' => [array_map(static fn (int $i): int => 1_700_000_000 + $i * 60, range(1, 5_000))],
    ]);
});

it('still decodes an ordinary frame', function () {
    $frame = ['t' => 'query', 'q' => [['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => 'r1']]]];

    expect(MessagePack::decode(MessagePack::encode($frame)))->toBe($frame);
});

describe('a frame bound for the renderer', function () {
    it('holds only arrays and scalars', function (mixed $value) {
        expect(fn () => MessagePackCodec::assertPlain(['data' => ['nested' => $value]]))
            ->toThrow(CodecException::class, 'only arrays and scalars');
    })->with([
        'object' => [new \stdClass()],
        'date' => [new \DateTimeImmutable()],
    ]);

    it('holds no closure', function () {
        expect(fn () => MessagePackCodec::assertPlain(['data' => [static fn (): int => 1]]))
            ->toThrow(CodecException::class, 'Cannot encode Closure');
    });

    it('lets arrays and scalars through', function () {
        MessagePackCodec::assertPlain(['a' => [1, 'b', 2.5, true, null, ['c' => []]]]);

        expect(true)->toBeTrue();
    });
});
