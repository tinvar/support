<?php

declare(strict_types=1);

/**
 * Where an application's generated secrets live: this user's state
 * directory, one file per application and kind (ADR 0082, 0150).
 */

use Tinvar\Support\StateFile;

it('names a file per application, in XDG_STATE_HOME or under HOME', function () {
    $root = sys_get_temp_dir();
    $real = (string) realpath($root);
    $stem = StateFile::stem($real);

    expect(StateFile::of($root, 'key', ['XDG_STATE_HOME' => '/state', 'HOME' => '/home/me']))->toBe("/state/tinvar/{$stem}.key")
        ->and(StateFile::of($root, 'probe-token', ['XDG_STATE_HOME' => 'relative', 'HOME' => '/home/me']))->toBe("/home/me/.local/state/tinvar/{$stem}.probe-token")
        ->and(StateFile::of($root, 'key', ['HOME' => 'nowhere']))->toBeNull()
        ->and($stem)->toMatch('/^[a-z0-9-]+-[0-9a-f]{12}$/');
});

it('names two applications of one name apart, by their paths', function () {
    expect(StateFile::stem('/srv/one/my-app'))->not->toBe(StateFile::stem('/srv/two/my-app'))
        ->and(StateFile::stem('/srv/one/My App!'))->toStartWith('my-app-');
});

it('refuses an extension that could climb out of the directory', function () {
    StateFile::of('/srv/app', '../key', ['HOME' => '/home/me']);
})->throws(InvalidArgumentException::class);
