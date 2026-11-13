<?php

declare(strict_types=1);

use Tinvar\Support\DevMode;

describe('DevMode::notDevelopment()', function (): void {
    it('is null only where TINVAR_ENV says local, development or testing', function (string $value): void {
        expect(DevMode::notDevelopment(['TINVAR_ENV' => $value]))->toBeNull()
            ->and(DevMode::isDevelopment(['TINVAR_ENV' => $value]))->toBeTrue();
    })->with(['local', 'development', 'testing', ' Local ']);

    it('reads an environment that says nothing as one that may be production', function (): void {
        expect(DevMode::notDevelopment([]))->toBe('TINVAR_ENV is not local, development or testing')
            ->and(DevMode::notDevelopment(['TINVAR_ENV' => '']))->toBe('TINVAR_ENV is not local, development or testing')
            ->and(DevMode::notDevelopment(['TINVAR_ENV' => 'dev']))->toBe('TINVAR_ENV is not local, development or testing')
            ->and(DevMode::notDevelopment(['APP_ENV' => 'local']))->toBe('TINVAR_ENV is not local, development or testing')
            ->and(DevMode::isDevelopment([]))->toBeFalse();
    });

    it('names a production or staging marker over a development TINVAR_ENV', function (array $env, string $why): void {
        expect(DevMode::notDevelopment(['TINVAR_ENV' => 'local', ...$env]))->toBe($why);
    })->with([
        'APP_ENV production' => [['APP_ENV' => 'production'], 'APP_ENV=production'],
        'NODE_ENV production' => [['NODE_ENV' => 'Production'], 'NODE_ENV=production'],
        'APP_ENV staging' => [['APP_ENV' => 'staging'], 'APP_ENV=staging'],
        'TINVAR_PROFILE staging' => [['TINVAR_PROFILE' => 'staging'], 'TINVAR_PROFILE=staging'],
        'TINVAR_PROFILE production' => [['TINVAR_PROFILE' => 'production'], 'TINVAR_PROFILE=production'],
    ]);

    it('names TINVAR_ENV itself where it says production or staging', function (): void {
        expect(DevMode::notDevelopment(['TINVAR_ENV' => 'production']))->toBe('TINVAR_ENV=production')
            ->and(DevMode::notDevelopment(['TINVAR_ENV' => 'staging']))->toBe('TINVAR_ENV=staging');
    });
});

describe('DevMode::check()', function (): void {
    it('refuses TINVAR_DEV=1 where nothing says this is a developer\'s machine', function (): void {
        expect(fn () => DevMode::fromEnvironment(['TINVAR_DEV' => '1']))
            ->toThrow(RuntimeException::class, 'TINVAR_DEV=1 is refused: TINVAR_ENV is not local, development or testing');
    });

    it('allows TINVAR_DEV=1 where TINVAR_ENV says local', function (): void {
        expect(DevMode::fromEnvironment(['TINVAR_DEV' => '1', 'TINVAR_ENV' => 'local']))->toBeTrue()
            ->and(DevMode::fromEnvironment(['TINVAR_ENV' => 'local']))->toBeFalse();
    });

    it('still refuses TINVAR_DEV=1 beside a production marker', function (): void {
        expect(fn () => DevMode::fromEnvironment(['TINVAR_DEV' => '1', 'TINVAR_ENV' => 'local', 'APP_ENV' => 'production']))
            ->toThrow(RuntimeException::class, 'APP_ENV=production');
    });
});
