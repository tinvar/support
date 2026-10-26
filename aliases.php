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
spl_autoload_register(static function (string $class): void {
    $renamed = [
        'tinvar\\data\\lazy' => Tinvar\Support\Lazy::class,
        'tinvar\\attributes\\dependency' => Tinvar\Container\Dependency::class,
    ][strtolower(ltrim($class, '\\'))] ?? null;

    if ($renamed !== null) {
        @trigger_error("{$class} is deprecated since tinvar/support 0.4 and is removed in 0.5; use {$renamed}.", \E_USER_DEPRECATED);
        class_alias($renamed, $class);
    }
});
