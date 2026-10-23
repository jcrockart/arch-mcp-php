<?php

namespace ArchMcp;

require_once __DIR__.'/GateConfigException.php';

/**
 * Reads one lane's entry from a project's arch-gate.json: the gate steps run
 * before a commit, the paths that get staged, and whether the commit is
 * versioned.
 *
 * Step forms:
 *   {"name": "validate", "builtin": "validate"}   the validator built into this server
 *   {"name": "tests", "cmd": ["php", "t.php"]}    a command, run from the project root
 * The older form {"name": "validate", "cmd": ["python3", "validate.py"]}, exactly
 * that command, is read as the built-in validator, so projects that still list
 * it keep working without a copy of validate.py.
 *
 * The metadata lane needs no entry: it defaults to the built-in validator and
 * the metadata/ stage path. Any other lane must have an entry.
 *
 * Versioning has one rule: an explicit "versioned" key decides; without it a
 * lane is versioned when it is the metadata lane or its stage paths include
 * metadata/.
 */
final class GateConfig
{
    public const BUILTIN_VALIDATE = 'validate';

    /**
     * @return array{gate: list<array{name: string, builtin?: string, cmd?: list<string>}>, stage: array{label: string, paths: list<string>}, versioned: bool}
     *
     * @throws GateConfigException
     */
    public static function load(string $repoPath, string $lane): array
    {
        $file = rtrim($repoPath, '/').'/arch-gate.json';
        $config = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!\is_array($decoded)) {
                throw new GateConfigException('arch-gate.json is not valid JSON, so the gate for lane \''.$lane.'\' cannot be read.');
            }
            $config = $decoded;
        }

        if (isset($config[$lane]) && \is_array($config[$lane])) {
            return self::fromEntry($lane, $config[$lane]);
        }
        if ('metadata' === $lane) {
            return self::fromEntry($lane, [
                'gate' => [['name' => 'validate', 'builtin' => self::BUILTIN_VALIDATE]],
                'stage' => ['label' => 'metadata', 'paths' => ['metadata']],
            ]);
        }

        throw new GateConfigException("No configuration found for lane '{$lane}' in arch-gate.json. Add a \"{$lane}\": {\"gate\": [...], \"stage\": {...}} entry before starting a '{$lane}' session.");
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{gate: list<array{name: string, builtin?: string, cmd?: list<string>}>, stage: array{label: string, paths: list<string>}, versioned: bool}
     */
    private static function fromEntry(string $lane, array $entry): array
    {
        if (!isset($entry['gate']) || !\is_array($entry['gate'])) {
            throw new GateConfigException("Lane '{$lane}' in arch-gate.json has no \"gate\" list.");
        }
        $steps = [];
        foreach (array_values($entry['gate']) as $i => $step) {
            $steps[] = self::step($lane, $i, $step);
        }

        $stage = $entry['stage'] ?? null;
        if (!\is_array($stage) || !isset($stage['paths']) || !\is_array($stage['paths'])) {
            throw new GateConfigException("Lane '{$lane}' in arch-gate.json has no \"stage\" with a \"paths\" list.");
        }
        $paths = [];
        foreach ($stage['paths'] as $p) {
            if (!\is_string($p) || '' === $p) {
                throw new GateConfigException("Lane '{$lane}' in arch-gate.json has a stage path that is not a non-empty string.");
            }
            $paths[] = $p;
        }
        $label = isset($stage['label']) && \is_string($stage['label']) && '' !== $stage['label'] ? $stage['label'] : $lane;

        return [
            'gate' => $steps,
            'stage' => ['label' => $label, 'paths' => $paths],
            'versioned' => self::isVersioned($lane, $entry, $paths),
        ];
    }

    /**
     * @return array{name: string, builtin?: string, cmd?: list<string>}
     */
    private static function step(string $lane, int $i, mixed $step): array
    {
        $at = "Lane '{$lane}' gate step ".($i + 1);
        if (!\is_array($step) || !isset($step['name']) || !\is_string($step['name']) || '' === $step['name']) {
            throw new GateConfigException("{$at} in arch-gate.json needs a \"name\".");
        }
        $name = $step['name'];

        if (isset($step['builtin'])) {
            if (self::BUILTIN_VALIDATE !== $step['builtin']) {
                throw new GateConfigException("{$at} ('{$name}') names an unknown built-in step. The only one is \"".self::BUILTIN_VALIDATE.'".');
            }

            return ['name' => $name, 'builtin' => self::BUILTIN_VALIDATE];
        }

        $cmd = $step['cmd'] ?? null;
        if (!\is_array($cmd) || [] === $cmd || array_values($cmd) !== $cmd) {
            throw new GateConfigException("{$at} ('{$name}') needs a \"cmd\" list or a \"builtin\".");
        }
        foreach ($cmd as $part) {
            if (!\is_string($part)) {
                throw new GateConfigException("{$at} ('{$name}') has a \"cmd\" entry that is not a string.");
            }
        }
        if (['python3', 'validate.py'] === $cmd) {
            return ['name' => $name, 'builtin' => self::BUILTIN_VALIDATE];
        }

        return ['name' => $name, 'cmd' => $cmd];
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $paths
     */
    private static function isVersioned(string $lane, array $entry, array $paths): bool
    {
        if (\array_key_exists('versioned', $entry)) {
            return (bool) $entry['versioned'];
        }
        if ('metadata' === $lane) {
            return true;
        }
        foreach ($paths as $p) {
            $p = trim(str_replace('\\', '/', $p), '/');
            if ('metadata' === $p || str_starts_with($p, 'metadata/')) {
                return true;
            }
        }

        return false;
    }
}
