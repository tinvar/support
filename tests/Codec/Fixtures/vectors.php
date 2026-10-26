<?php

declare(strict_types=1);

/**
 * Shared MessagePack conformance vectors.
 *
 * The same table is encoded by packages/renderer/tests/codec.test.ts, so the two
 * implementations are pinned to identical wire bytes rather than merely
 * round-tripping within their own language.
 */
function messagePackVectors(): array
{
    return [
        'nil' => [null, 'c0'],
        'true' => [true, 'c3'],
        'false' => [false, 'c2'],
        'zero' => [0, '00'],
        'fixint' => [127, '7f'],
        'uint8' => [200, 'ccc8'],
        'uint16' => [65535, 'cdffff'],
        'uint32' => [4294967295, 'ceffffffff'],
        'beyond_2_53' => [9007199254740993, 'cf0020000000000001'],
        'int64_max' => [PHP_INT_MAX, 'cf7fffffffffffffff'],
        'neg_fixint' => [-1, 'ff'],
        'neg_32' => [-32, 'e0'],
        'int8' => [-128, 'd080'],
        'int16' => [-32768, 'd18000'],
        'int32' => [-2147483648, 'd280000000'],
        // Safe in JavaScript but past 32 bits: a millisecond timestamp, a
        // file size. int64 on the wire in both directions, so PHP reads an
        // int and the renderer a number.
        'uint64_safe' => [5_000_000_000, 'cf000000012a05f200'],
        'int64_safe' => [-5_000_000_000, 'd3fffffffed5fa0e00'],
        'int64_min' => [PHP_INT_MIN, 'd38000000000000000'],
        'float' => [3.141592653589793, 'cb400921fb54442d18'],
        'str' => ['R-14 · Racks', 'ad522d313420c2b7205261636b73'],
        'empty_list' => [[], '90'],
        'list' => [[1, 2, 3], '93010203'],
        'map' => [['t' => 'render', 'n' => 42], '82a174a672656e646572a16e2a'],
    ];
}
