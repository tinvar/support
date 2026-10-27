<?php

declare(strict_types=1);

/**
 * An attribute naming a Tinvar class that does not exist is refused, rather
 * than matched by nothing and so silently absent (ADR 0055).
 */

namespace Tinvar\Tests\Support\KnownAttributes;

use Tinvar\Support\KnownAttributes;
use Tinvar\Support\RenamedClasses;

#[\Attribute]
final class Own {}

#[Own, \Tinvar\Support\Lazy]
final class Known
{
    #[Own] public string $a = '';

    public function run(#[\SensitiveParameter] string $b): void {}
}

#[\Tinvar\Attributes\NoSuchThing]
final class OnClass {}

final class OnProperty
{
    #[\Tinvar\Attributes\Gone] public string $a = '';
}

final class OnParameter
{
    public function run(#[\Tinvar\Attributes\Gone] string $b): void {}
}

final class OnConstant
{
    #[\Tinvar\Attributes\Gone]
    public const int A = 1;
}

#[\Elsewhere\NoSuchThing]
final class NotTinvars {}

it('passes attributes that load, and names that are not Tinvar\'s', function () {
    KnownAttributes::check(new \ReflectionClass(Known::class));
    KnownAttributes::check(new \ReflectionClass(NotTinvars::class));

    expect(true)->toBeTrue();
});

it('refuses a Tinvar attribute naming no class, wherever it is written', function (string $class, string $where) {
    expect(fn () => KnownAttributes::check(new \ReflectionClass($class)))
        ->toThrow(\LogicException::class, "{$where} carries #[");
})->with([
    'on the class' => [OnClass::class, OnClass::class],
    'on a property' => [OnProperty::class, OnProperty::class . '::$a'],
    'on a parameter' => [OnParameter::class, OnParameter::class . '::run() parameter $b'],
    'on a constant' => [OnConstant::class, OnConstant::class . '::A'],
]);

it('says what an old name became, where a release renamed it', function () {
    RenamedClasses::record(['tinvar\\attributes\\gone' => 'Tinvar\\Support\\Lazy']);

    expect(fn () => KnownAttributes::check(new \ReflectionClass(OnProperty::class)))->toThrow(
        \LogicException::class,
        OnProperty::class . '::$a carries #[\Tinvar\Attributes\Gone], which is no class now: it was renamed Tinvar\Support\Lazy.',
    )->and(fn () => KnownAttributes::check(new \ReflectionClass(OnClass::class)))->toThrow(
        \LogicException::class,
        OnClass::class . ' carries #[\Tinvar\Attributes\NoSuchThing], which is no class. An attribute naming no class is ignored',
    );
});
