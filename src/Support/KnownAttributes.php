<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Refuses an attribute that names a Tinvar class there is none of.
 *
 * PHP matches an attribute by the class it names, and one naming a class
 * that does not exist is never matched: nothing fails until newInstance(),
 * which code asking for another attribute never calls. So a model still
 * written with the ORM's tenant-scoped attribute under its name from before
 * the split, once that name is no alias of anything -- the aliases go in
 * 0.5 (ADR 0055) -- would compile as not tenant-scoped and read every
 * tenant's rows, and a misspelt attribute
 * on an entry point or a payload would drop what it declared the same way.
 * What compiles attributes for security -- the ORM's metadata, the
 * container, Data and validation, the registries and the build rules --
 * asks here first, and an attribute under `Tinvar\` that does not load
 * fails closed, saying what an old name became where a release renamed it
 * (RenamedClasses).
 *
 * Only Tinvar's names: an application's own attributes, and PHP's, are not
 * this rule's to judge.
 */
final class KnownAttributes
{
    /**
     * Checks the attributes on $class and on its properties, constants,
     * methods and their parameters.
     *
     * @param \ReflectionClass<object> $class
     * @throws \LogicException naming the first attribute whose class does not exist
     */
    public static function check(\ReflectionClass $class): void
    {
        $name = $class->getName();
        self::checkOn($class, $name);

        foreach ($class->getProperties() as $property) {
            self::checkOn($property, "{$name}::\${$property->getName()}");
        }
        foreach ($class->getReflectionConstants() as $constant) {
            self::checkOn($constant, "{$name}::{$constant->getName()}");
        }
        foreach ($class->getMethods() as $method) {
            self::checkOn($method, "{$name}::{$method->getName()}()");
            foreach ($method->getParameters() as $parameter) {
                self::checkOn($parameter, "{$name}::{$method->getName()}() parameter \${$parameter->getName()}");
            }
        }
    }

    /**
     * Checks the attributes of one declaration.
     *
     * @param \ReflectionClass<object>|\ReflectionProperty|\ReflectionMethod|\ReflectionParameter|\ReflectionClassConstant $declaration
     * @throws \LogicException naming the first attribute whose class does not exist
     */
    public static function checkOn(
        \ReflectionClass|\ReflectionProperty|\ReflectionMethod|\ReflectionParameter|\ReflectionClassConstant $declaration,
        string $where,
    ): void {
        foreach ($declaration->getAttributes() as $attribute) {
            $attributeName = $attribute->getName();
            if (!str_starts_with(strtolower($attributeName), 'tinvar\\') || class_exists($attributeName)) {
                continue;
            }

            $current = RenamedClasses::current($attributeName);

            throw new \LogicException(
                $current !== null
                    ? "{$where} carries #[\\{$attributeName}], which is no class now: it was renamed {$current}. "
                        . 'Write the new name; under the old one the attribute would be ignored (ADR 0055).'
                    : "{$where} carries #[\\{$attributeName}], which is no class. An attribute naming no class is "
                        . 'ignored, so what it declares would not hold: correct the name.',
            );
        }
    }
}
