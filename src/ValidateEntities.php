<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateFiles.php';
require_once __DIR__.'/PyValue.php';

/**
 * Section 1 of the metadata validator: metadata/entities/*.json. Pass 1 checks
 * each file and records what later checks need in the context; pass 2 checks
 * the `key` and `relations` cross-references once every entity is known.
 */
final class ValidateEntities
{
    public const ALLOWED_TYPES = ['String', 'Boolean', 'Integer', 'Decimal', 'Guid', 'DateTime', 'Money', 'Percentage', 'Email', 'Code'];
    public const PASCAL = '/^[A-Z][A-Za-z0-9]*$/';

    public static function run(ValidateContext $ctx, string $metaDir): void
    {
        foreach (ValidateFiles::glob($metaDir.'/entities', '.json') as $path) {
            $ctx->guard($path, static fn () => self::file($ctx, $path));
        }
        foreach ($ctx->entityKeys as $name => $key) {
            $ctx->guard("entity '{$name}'", static fn () => self::checkKey($ctx, (string) $name, $key));
        }
        foreach ($ctx->entityRelations as $name => $relations) {
            $ctx->guard("entity '{$name}'", static fn () => self::checkRelations($ctx, (string) $name, $relations));
        }
    }

    private static function file(ValidateContext $ctx, string $path): void
    {
        $data = ValidateFiles::json($ctx, $path);
        if (null === $data) {
            return;
        }
        if (!$data instanceof \stdClass) {
            throw new ValidateCrash('the top-level value must be an object');
        }

        $name = PyValue::get($data, 'entity');
        if (!PyValue::truthy($name)) {
            $ctx->fail("{$path}: missing required field 'entity'");

            return;
        }
        if (PyValue::matches(self::PASCAL, $name)) {
            $ctx->entityNames[$name] = true;
        } else {
            $ctx->fail("{$path}: entity name '".PyValue::str($name)."' must be PascalCase (e.g. 'Todo', not 'task')");
        }

        $attrs = PyValue::get($data, 'attributes');
        $attrNames = [];
        if (!PyValue::truthy($attrs)) {
            $ctx->fail("{$path}: entity '{$name}' has no attributes (minProperties: 1)");
        } else {
            if (!$attrs instanceof \stdClass) {
                throw new ValidateCrash("'attributes' must be an object");
            }
            foreach (PyValue::items($attrs) as [$attr, $spec]) {
                $attrNames[$attr] = true;
                self::attribute($ctx, $path, $attr, $spec);
            }
        }
        $ctx->entityAttrs[$name] = $attrNames;

        $invariants = PyValue::iterate(PyValue::get($data, 'invariants', []));
        if (null === $invariants) {
            throw new ValidateCrash("'invariants' must be a list");
        }
        foreach ($invariants as $inv) {
            if (!\is_string($inv) || PyValue::blank($inv)) {
                $ctx->fail(
                    "{$path}: entity '{$name}' has an empty or invalid invariant. "
                    ."Per 32-ARCH-DOMAIN-MODELS, 'AI cannot infer rules that are not written down' "
                    .'— an empty invariant is worse than none: it signals an unmade decision.'
                );
            }
        }

        if (PyValue::has($data, 'key')) {
            $ctx->entityKeys[$name] = PyValue::get($data, 'key');
        }
        if (PyValue::has($data, 'relations')) {
            $ctx->entityRelations[$name] = PyValue::get($data, 'relations');
        }
    }

    private static function attribute(ValidateContext $ctx, string $path, string $attr, mixed $spec): void
    {
        $types = PyValue::pyList(self::ALLOWED_TYPES);
        if (\is_string($spec)) {
            if (!\in_array($spec, self::ALLOWED_TYPES, true)) {
                $ctx->fail(
                    "{$path}: attribute '{$attr}' has type '{$spec}', which is not in the "
                    ."allowed primitive/domain type list ({$types}). "
                    .'Per 31-ARCH-META-SCHEMA, domain-specific types must be defined in '
                    .'32-ARCH-DOMAIN-MODELS before use.'
                );
            }

            return;
        }
        if (!$spec instanceof \stdClass) {
            $ctx->fail("{$path}: attribute '{$attr}' must be a type string or a computed-attribute object");

            return;
        }
        $extra = array_diff(PyValue::keys($spec), ['type', 'computed']);
        if ([] !== $extra) {
            $ctx->fail("{$path}: attribute '{$attr}' (computed) has unexpected key(s) ".PyValue::pyList($extra));
        }
        $type = PyValue::get($spec, 'type');
        if (!PyValue::inList($type, self::ALLOWED_TYPES)) {
            $ctx->fail(
                "{$path}: computed attribute '{$attr}' has type '".PyValue::str($type)."', which is not in "
                ."the allowed type list ({$types})"
            );
        }
        $derivation = PyValue::get($spec, 'computed');
        if (!\is_string($derivation) || PyValue::blank($derivation)) {
            $ctx->fail(
                "{$path}: computed attribute '{$attr}' must carry a non-empty 'computed' "
                .'derivation string — an empty derivation is worse than none, same '
                .'principle as empty invariants below.'
            );
        }
    }

    private static function checkKey(ValidateContext $ctx, string $name, mixed $key): void
    {
        if (!\is_array($key) || [] === $key) {
            $ctx->fail("entity '{$name}': 'key' must be a non-empty array of attribute names");

            return;
        }
        $attrs = $ctx->entityAttrs[$name] ?? [];
        foreach ($key as $k) {
            if (!PyValue::inKeys($k, $attrs)) {
                $ctx->fail("entity '{$name}': key attribute '".PyValue::str($k)."' is not declared in this entity's attributes");
            }
        }
    }

    private static function checkRelations(ValidateContext $ctx, string $name, mixed $relations): void
    {
        if (!$relations instanceof \stdClass || !PyValue::truthy($relations)) {
            $ctx->fail("entity '{$name}': 'relations' must be a non-empty object");

            return;
        }
        $attrs = $ctx->entityAttrs[$name] ?? [];
        foreach (PyValue::items($relations) as [$relName, $rel]) {
            if (!$rel instanceof \stdClass) {
                $ctx->fail("entity '{$name}': relation '{$relName}' must be an object");
                continue;
            }
            $wanted = ['target', 'cardinality', 'via'];
            $extra = array_diff(PyValue::keys($rel), $wanted);
            $missing = array_diff($wanted, PyValue::keys($rel));
            if ([] !== $extra) {
                $ctx->fail("entity '{$name}': relation '{$relName}' has unexpected key(s) ".PyValue::pyList($extra));
            }
            if ([] !== $missing) {
                $ctx->fail("entity '{$name}': relation '{$relName}' is missing required key(s) ".PyValue::pyList($missing));
                continue;
            }
            $target = PyValue::get($rel, 'target');
            if (!PyValue::inKeys($target, $ctx->entityNames)) {
                $ctx->fail(
                    "entity '{$name}': relation '{$relName}' targets '".PyValue::str($target)."', which does not "
                    .'exist as a defined entity'
                );
            }
            $cardinality = PyValue::get($rel, 'cardinality');
            if (!\in_array($cardinality, ['one', 'many'], true)) {
                $ctx->fail("entity '{$name}': relation '{$relName}' cardinality must be 'one' or 'many', got '".PyValue::str($cardinality)."'");
            }
            $via = PyValue::get($rel, 'via');
            if (!PyValue::inKeys($via, $attrs)) {
                $ctx->fail(
                    "entity '{$name}': relation '{$relName}' via-attribute '".PyValue::str($via)."' is not declared "
                    ."in this entity's own attributes"
                );
            }
        }
    }
}
