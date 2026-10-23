<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateEntities.php';
require_once __DIR__.'/ValidateFiles.php';
require_once __DIR__.'/PyValue.php';

/**
 * Section 4 of the metadata validator: metadata/integrations/*.api.json. Each
 * integration needs at least one endpoint with a `request` and `response`, and
 * both are type-checked recursively: a type name, an {"array": <type>}
 * wrapper, or an object of field names to those ({} means no fields).
 */
final class ValidateIntegrations
{
    public static function run(ValidateContext $ctx, string $metaDir): void
    {
        foreach (ValidateFiles::glob($metaDir.'/integrations', '.api.json') as $path) {
            $ctx->guard($path, static fn () => self::file($ctx, $path));
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

        $endpoints = PyValue::get($data, 'endpoints', new \stdClass());
        if (!PyValue::truthy($endpoints)) {
            $ctx->fail("{$path}: integration declares no endpoints");
        }
        if (!$endpoints instanceof \stdClass) {
            throw new ValidateCrash("'endpoints' must be an object");
        }
        foreach (PyValue::items($endpoints) as [$name, $ep]) {
            if (!$ep instanceof \stdClass) {
                // Never valid: either it lacks request/response, or it is not an object at all.
                $ctx->fail("{$path}: endpoint '{$name}' missing 'request' or 'response'");
                continue;
            }
            if (!PyValue::has($ep, 'request') || !PyValue::has($ep, 'response')) {
                $ctx->fail("{$path}: endpoint '{$name}' missing 'request' or 'response'");
                continue;
            }
            self::typeRef($ctx, PyValue::get($ep, 'request'), $path, "endpoint '{$name}' request");
            self::typeRef($ctx, PyValue::get($ep, 'response'), $path, "endpoint '{$name}' response");
        }
    }

    private static function typeRef(ValidateContext $ctx, mixed $value, string $path, string $where): void
    {
        if (\is_string($value)) {
            if (!\in_array($value, ValidateEntities::ALLOWED_TYPES, true)) {
                $ctx->fail("{$path}: {$where} has type '{$value}', not in the allowed type list (".PyValue::pyList(ValidateEntities::ALLOWED_TYPES).')');
            }

            return;
        }
        if ($value instanceof \stdClass) {
            $keys = PyValue::keys($value);
            if (['array'] === $keys) {
                self::typeRef($ctx, PyValue::get($value, 'array'), $path, "{$where}[]");

                return;
            }
            if (\in_array('array', $keys, true) && \count($keys) > 1) {
                $ctx->fail("{$path}: {$where} mixes 'array' with other keys — an array wrapper must be the only key");

                return;
            }
            foreach (PyValue::items($value) as [$k, $v]) {
                self::typeRef($ctx, $v, $path, "{$where}.{$k}");
            }

            return;
        }
        $ctx->fail("{$path}: {$where} must be a type string, an object shape, or an {'array': ...} wrapper — got ".PyValue::typeName($value));
    }
}
