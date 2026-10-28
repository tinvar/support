<?php

declare(strict_types=1);

/**
 * "#[\NoDiscard] ... ActionResult, builders and Lazy resolvers that must be
 * used" (tinvar-spec.md, "PHP 8.5 features used"). A Lazy made and dropped
 * resolves nothing, so dropping one is warned of where it happens.
 */

use Tinvar\Support\Lazy;

it('marks the factories NoDiscard', function (string $method) {
    expect((new ReflectionMethod(Lazy::class, $method))->getAttributes('NoDiscard'))->not->toBeEmpty();
})->with(['of', 'value']);

it('warns when one is made and dropped', function () {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        Lazy::of(static fn (): int => 1);
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Lazy::of()');
});
