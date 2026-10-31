<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * A class's or a method's parameters, reflected once a worker.
 *
 * What a class declares does not change while a worker runs, and the
 * request path reflected the same constructors and methods on every call
 * -- an action's form requests, an endpoint's method, a loader's
 * `__invoke`. Only what the code declares is kept, never anything of a
 * request, so the cache is the same for every request a worker serves; and
 * it is bounded by the classes the application has.
 */
final class Reflected
{
    /** @var array<string, list<\ReflectionParameter>> */
    private static array $parameters = [];

    /**
     * The parameters of $class's constructor; [] for none.
     *
     * @param class-string $class
     * @return list<\ReflectionParameter>
     */
    public static function constructorParameters(string $class): array
    {
        return self::$parameters["{$class}::__construct"] ??= new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
    }

    /**
     * The parameters of $class's $method.
     *
     * @param class-string $class
     * @return list<\ReflectionParameter>
     * @throws \ReflectionException for a method $class does not have
     */
    public static function methodParameters(string $class, string $method): array
    {
        return self::$parameters["{$class}::" . strtolower($method)] ??= new \ReflectionMethod($class, $method)->getParameters();
    }
}
