<?php

declare(strict_types=1);

/**
 * The names support's classes had before the framework was split into
 * packages, kept for one release (ADR 0055).
 *
 * @deprecated since 0.4: use Tinvar\Support\Lazy and Tinvar\Container\Dependency.
 *             Removed in 0.5.
 *
 * An old name is aliased when something first asks for it, not when this
 * file loads, so an application that has moved to the new names loads
 * neither class here, and one that has not is told so once per process, as
 * a deprecation. The alias is the class itself: `instanceof`, attributes
 * read as instances, and type names compared with is_a() all see one class.
 *
 * It lives beside src/ rather than in it, because it names the old
 * namespaces, which are the framework's, on purpose: the layer rule reads
 * src/ alone.
 */
$renamed = [
    'tinvar\\data\\lazy' => Tinvar\Support\Lazy::class,
    'tinvar\\attributes\\dependency' => Tinvar\Container\Dependency::class,
];

// Recorded whether or not an alias is ever made, so a refusal of an old
// name can say what it became (Tinvar\Support\KnownAttributes).
Tinvar\Support\RenamedClasses::record($renamed);

spl_autoload_register(static function (string $class) use ($renamed): void {
    $current = $renamed[strtolower(ltrim($class, '\\'))] ?? null;

    if ($current !== null) {
        @trigger_error("{$class} is deprecated since tinvar/support 0.4 and is removed in 0.5; use {$current}.", \E_USER_DEPRECATED);
        class_alias($current, $class);
    }
});
