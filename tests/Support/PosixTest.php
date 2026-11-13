<?php

declare(strict_types=1);

use Tinvar\Support\Posix;

/**
 * Runs $code in a PHP of its own with posix_geteuid() disabled -- as a PHP
 * built without ext-posix has none -- and returns its exit status and output.
 *
 * @return array{int, string}
 */
function withoutPosix(string $code): array
{
    // The autoloader this run loaded: the workspace's, or package-alone's.
    $autoload = realpath(Composer\InstalledVersions::getRootPackage()['install_path']) . '/vendor/autoload.php';
    $process = proc_open(
        [PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', 'require ' . var_export($autoload, true) . '; ' . $code],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
    );
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

it('is this process\'s effective user where ext-posix is loaded', function () {
    expect(Posix::effectiveUser('a test'))->toBe(posix_geteuid());
});

it('fails closed where ext-posix is not loaded, naming what it refuses', function () {
    [$status, $output] = withoutPosix('Tinvar\Support\Posix::effectiveUser("the application key file");');

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Refusing the application key file: ext-posix is not loaded');
});

// AppKey believed any key file at all where it could not check the owner.
it('refuses the application key file where ext-posix is not loaded, rather than believing it', function () {
    $directory = sys_get_temp_dir() . '/tinvar-posix-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $file = "{$directory}/app.key";
    file_put_contents($file, str_repeat('k', 64));
    chmod($file, 0600);

    try {
        [$status, $output] = withoutPosix('echo Tinvar\Support\AppKey::resolve("TINVAR_POSIX_TEST_KEY_UNSET", ' . var_export($file, true) . ');');

        expect($status)->not->toBe(0)
            ->and($output)->toContain('ext-posix is not loaded')
            ->and($output)->not->toContain(str_repeat('k', 64));
    } finally {
        unlink($file);
        rmdir($directory);
    }
});

// Every package that checks who owns what requires ext-posix (ADR 0212): an
// install that cannot check is not one Tinvar runs on.
it('is required by every PHP package that checks who owns what', function (string $package) {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['require'] ?? [])->toHaveKey('ext-posix');
})->with(['support', 'framework', 'testing']);
