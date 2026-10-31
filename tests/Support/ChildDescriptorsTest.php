<?php

declare(strict_types=1);

use Tinvar\Support\ChildDescriptors;

/*
 * PHP opens nothing close-on-exec, so a child it starts holds every file and
 * socket it does, under the same numbers -- and a renderer's permission
 * model checks paths, never a descriptor already open. A child is given
 * /dev/null in place of each one instead.
 */

it('lists each descriptor above 2 this process holds', function () {
    $before = ChildDescriptors::open();
    $file = fopen(__FILE__, 'r');
    $after = ChildDescriptors::open();
    fclose($file);

    expect(array_diff($after, $before))->not->toBeEmpty()
        ->and(min($after))->toBeGreaterThan(2)
        ->and($after)->toBe(array_values(array_unique($after)));
});

it('gives the child /dev/null for each one, and keeps what the spec names itself', function () {
    $file = fopen(__FILE__, 'r');
    $open = ChildDescriptors::open();
    $spec = ChildDescriptors::closing([0 => ['pipe', 'r'], 1 => STDERR, 2 => ['file', '/dev/null', 'w']]);
    fclose($file);

    expect($spec[0])->toBe(['pipe', 'r'])
        ->and($spec[1])->toBe(STDERR)
        ->and($spec[2])->toBe(['file', '/dev/null', 'w']);
    foreach ($open as $fd) {
        expect($spec[$fd])->toBe(['file', '/dev/null', 'r']);
    }
});

it('leaves a descriptor the spec hands on itself as the spec has it', function () {
    $file = fopen(__FILE__, 'r');
    $open = ChildDescriptors::open();
    $fd = max($open);
    $spec = ChildDescriptors::closing([0 => ['pipe', 'r'], $fd => $file]);
    fclose($file);

    expect($spec[$fd])->toBe($file);
});

it('starts a child that can read none of what this process holds', function () {
    $secret = tempnam(sys_get_temp_dir(), 'tinvar-fd-');
    file_put_contents($secret, 'inherited-secret');
    $held = fopen($secret, 'r');
    $code = <<<'PHP'
        $found = [];
        foreach (scandir(is_dir('/proc/self/fd') ? '/proc/self/fd' : '/dev/fd') as $fd) {
            if (ctype_digit($fd) && (int) $fd > 2 && str_contains((string) @file_get_contents("php://fd/{$fd}", false, null, 0, 64), 'inherited-secret')) {
                $found[] = $fd;
            }
        }
        echo $found === [] ? 'none' : implode(',', $found);
        PHP;

    $run = static function (array $spec) use ($code): string {
        $process = proc_open([PHP_BINARY, '-n', '-r', $code], $spec, $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $out;
    };

    try {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        // What a child is handed without it: the reason it exists.
        expect($run($spec))->not->toBe('none')
            ->and($run(ChildDescriptors::closing($spec)))->toBe('none');
    } finally {
        fclose($held);
        unlink($secret);
    }
});
