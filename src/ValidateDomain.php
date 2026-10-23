<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateEntities.php';
require_once __DIR__.'/ValidateFiles.php';
require_once __DIR__.'/PyValue.php';

/**
 * Section 2 of the metadata validator: metadata/domain/*.domain.json. Each
 * model must name an existing entity and declare PascalCase events.
 */
final class ValidateDomain
{
    public static function run(ValidateContext $ctx, string $metaDir): void
    {
        foreach (ValidateFiles::glob($metaDir.'/domain', '.domain.json') as $path) {
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

        $entity = PyValue::get($data, 'entity');
        if (!PyValue::truthy($entity)) {
            $ctx->fail("{$path}: missing required field 'entity'");
        } elseif (!PyValue::inKeys($entity, $ctx->entityNames)) {
            $ctx->fail(
                "{$path}: references entity '".PyValue::str($entity)."', which does not exist in "
                .'/metadata/entities. (32-ARCH-DOMAIN-MODELS is downstream of entities — '
                .'the entity must be defined first.)'
            );
        }

        $eventsValue = PyValue::get($data, 'events', []);
        if (!PyValue::truthy($eventsValue)) {
            $ctx->fail("{$path}: domain model for '".PyValue::str($entity)."' declares no events (minItems: 1)");
        }
        $events = PyValue::iterate($eventsValue);
        if (null === $events) {
            throw new ValidateCrash("'events' must be a list");
        }
        foreach ($events as $ev) {
            if (!PyValue::matches(ValidateEntities::PASCAL, $ev)) {
                $ctx->fail("{$path}: event '{$ev}' must be PascalCase, past-tense (e.g. 'TodoCreated')");
            }
        }
    }
}
