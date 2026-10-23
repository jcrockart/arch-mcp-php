<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateCrash.php';

/**
 * What the metadata validator's sections share: the errors found so far, and
 * the entity facts the later sections check against (names, attributes, keys,
 * relations). One instance per run. Does no I/O.
 */
final class ValidateContext
{
    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, true> entity names that passed the PascalCase check */
    public array $entityNames = [];

    /** @var array<string, array<string, true>> entity name => attribute names */
    public array $entityAttrs = [];

    /** @var array<string, mixed> entity name => raw `relations` value, checked in pass 2 */
    public array $entityRelations = [];

    /** @var array<string, mixed> entity name => raw `key` value, checked in pass 2 */
    public array $entityKeys = [];

    public function fail(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Run one unit of checking. A shape the checks cannot read becomes one
     * error naming $where, and the run goes on with the next unit.
     */
    public function guard(string $where, callable $check): void
    {
        try {
            $check();
        } catch (ValidateCrash $e) {
            $this->fail("{$where}: cannot be read as expected ({$e->getMessage()})");
        } catch (\Throwable $e) {
            // A fault in the validator itself must fail the commit loudly, never pass it.
            $this->fail("{$where}: the validator could not check this ({$e->getMessage()})");
        }
    }
}
