<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateFiles.php';
require_once __DIR__.'/PyValue.php';

/**
 * Section 3 of the metadata validator: metadata/workflows/*.workflow.json. The
 * name must be PascalCase ending in Workflow, and either `stateless: true` or
 * at least two states with transitions between declared states ("None" is the
 * implicit starting state).
 */
final class ValidateWorkflows
{
    private const NAME = '/^[A-Z][A-Za-z0-9]*Workflow$/';

    public static function run(ValidateContext $ctx, string $metaDir): void
    {
        foreach (ValidateFiles::glob($metaDir.'/workflows', '.workflow.json') as $path) {
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

        $name = PyValue::get($data, 'workflow', '');
        if (!PyValue::matches(self::NAME, $name)) {
            $ctx->fail("{$path}: workflow name '".PyValue::str($name)."' must be PascalCase and end in 'Workflow'");
        }

        $stateless = PyValue::truthy(PyValue::get($data, 'stateless', false));
        $hasStates = PyValue::has($data, 'states') || PyValue::has($data, 'transitions');
        if ($stateless && $hasStates) {
            $ctx->fail("{$path}: 'stateless: true' is mutually exclusive with 'states'/'transitions'");

            return;
        }
        if ($stateless) {
            return;
        }

        $list = PyValue::iterate(PyValue::get($data, 'states', []));
        if (null === $list) {
            throw new ValidateCrash("'states' must be a list");
        }
        $states = [];
        foreach ($list as $state) {
            $key = PyValue::hashKey($state);
            if (null === $key) {
                throw new ValidateCrash("a state must be a name, not a list or object");
            }
            $states[$key] = true;
        }
        if (\count($states) < 2) {
            $ctx->fail("{$path}: workflow declares fewer than 2 states (minItems: 2) — or set 'stateless: true' instead");
        }
        $states[PyValue::hashKey('None') ?? ''] = true;

        $transitions = PyValue::get($data, 'transitions', new \stdClass());
        if (!PyValue::truthy($transitions)) {
            $ctx->fail("{$path}: workflow declares no transitions");
        }
        if (!$transitions instanceof \stdClass) {
            throw new ValidateCrash("'transitions' must be an object");
        }
        foreach (PyValue::items($transitions) as [$tName, $t]) {
            if (!$t instanceof \stdClass) {
                throw new ValidateCrash("transition '{$tName}' must be an object");
            }
            $from = PyValue::get($t, 'from');
            $to = PyValue::get($t, 'to');
            if (!self::declared($from, $states)) {
                $ctx->fail("{$path}: transition '{$tName}' has from-state '".PyValue::str($from)."' not in declared states");
            }
            if (!self::declared($to, $states)) {
                $ctx->fail("{$path}: transition '{$tName}' has to-state '".PyValue::str($to)."' not in declared states");
            }
        }
    }

    /** @param array<string, true> $states hash keys of the declared states */
    private static function declared(mixed $state, array $states): bool
    {
        $key = PyValue::hashKey($state);
        if (null === $key) {
            throw new ValidateCrash('a transition state must be a name, not a list or object');
        }

        return isset($states[$key]);
    }
}
