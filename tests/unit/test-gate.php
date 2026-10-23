<?php

/*
 * Gate config and gate runner: step forms, the legacy validate.py mapping,
 * the metadata-lane default, the versioning rule, ordered runs that stop at
 * the first failure.
 */

require_once __DIR__.'/../../src/GateConfig.php';
require_once __DIR__.'/../../src/GateRunner.php';

use ArchMcp\GateConfig;
use ArchMcp\GateConfigException;
use ArchMcp\GateRunner;

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        ++$passed;
        echo "[PASS] $name\n";
    } else {
        ++$failed;
        echo "[FAIL] $name\n";
    }
}

function tmpProject(?array $gate, bool $validMetadata = true): string
{
    $d = sys_get_temp_dir().'/gate-test-'.bin2hex(random_bytes(6));
    mkdir($d, 0777, true);
    if (null !== $gate) {
        file_put_contents($d.'/arch-gate.json', json_encode($gate));
    }
    if ($validMetadata) {
        mkdir($d.'/metadata', 0777, true);
    }

    return $d;
}

function loads(string $d, string $lane): ?array
{
    try {
        return GateConfig::load($d, $lane);
    } catch (GateConfigException) {
        return null;
    }
}

function refuses(string $d, string $lane): bool
{
    try {
        GateConfig::load($d, $lane);
    } catch (GateConfigException) {
        return true;
    }

    return false;
}

// --- metadata lane default
$d = tmpProject(null);
$c = loads($d, 'metadata');
check('no gate file: metadata lane defaults to built-in validate', $c && [['name' => 'validate', 'builtin' => 'validate']] === $c['gate']);
check('metadata default stages metadata', $c && ['metadata'] === $c['stage']['paths'] && 'metadata' === $c['stage']['label']);
check('metadata default is versioned', $c && true === $c['versioned']);
check('other lane with no entry is refused', refuses($d, 'code'));

$d = tmpProject(['code' => ['gate' => [['name' => 'x', 'cmd' => ['true']]], 'stage' => ['label' => 'code', 'paths' => ['src']]]]);
$c = loads($d, 'metadata');
check('gate file without a metadata entry: metadata still defaults to validate', $c && isset($c['gate'][0]['builtin']));

// --- step forms
$d = tmpProject(['metadata' => ['gate' => [['name' => 'validate', 'cmd' => ['python3', 'validate.py']]], 'stage' => ['label' => 'metadata', 'paths' => ['metadata']]]]);
$c = loads($d, 'metadata');
check('exact python3 validate.py maps to built-in', $c && ['name' => 'validate', 'builtin' => 'validate'] === $c['gate'][0]);

$d = tmpProject(['code' => ['gate' => [['name' => 'v', 'cmd' => ['python3', 'validate.py', '--x']]], 'stage' => ['paths' => ['src']]]]);
$c = loads($d, 'code');
check('a different python3 validate.py command is left alone', $c && ['python3', 'validate.py', '--x'] === $c['gate'][0]['cmd']);

$d = tmpProject(['code' => ['gate' => [['name' => 'v', 'cmd' => ['python', 'validate.py']]], 'stage' => ['paths' => ['src']]]]);
$c = loads($d, 'code');
check('"python validate.py" is not the legacy form', $c && isset($c['gate'][0]['cmd']));

$d = tmpProject(['code' => ['gate' => [['name' => 'validate', 'builtin' => 'validate']], 'stage' => ['paths' => ['src']]]]);
$c = loads($d, 'code');
check('builtin step form is read', $c && ['name' => 'validate', 'builtin' => 'validate'] === $c['gate'][0]);
check('stage label defaults to the lane name', $c && 'code' === $c['stage']['label']);

foreach ([
    'unknown builtin' => [['name' => 'a', 'builtin' => 'nope']],
    'no name' => [['cmd' => ['true']]],
    'no cmd or builtin' => [['name' => 'a']],
    'empty cmd' => [['name' => 'a', 'cmd' => []]],
    'cmd with non-string' => [['name' => 'a', 'cmd' => ['x', 1]]],
    'cmd as a string' => [['name' => 'a', 'cmd' => 'true']],
] as $label => $gate) {
    $d = tmpProject(['code' => ['gate' => $gate, 'stage' => ['paths' => ['src']]]]);
    check("bad step refused: $label", refuses($d, 'code'));
}

$d = tmpProject(['code' => ['stage' => ['paths' => ['src']]]]);
check('entry with no gate list refused', refuses($d, 'code'));
$d = tmpProject(['code' => ['gate' => [], 'stage' => ['paths' => 'src']]]);
check('stage paths that are not a list refused', refuses($d, 'code'));
$d = tmpProject(['code' => ['gate' => [], 'stage' => ['paths' => ['']]]]);
check('empty stage path refused', refuses($d, 'code'));
$d = tmpProject(['code' => ['gate' => [], 'stage' => ['paths' => ['src']]]]);
$c = loads($d, 'code');
check('empty gate list is allowed', $c && [] === $c['gate']);

$d = tmpProject(null, false);
file_put_contents($d.'/arch-gate.json', '{not json');
check('invalid arch-gate.json refused', refuses($d, 'metadata'));

// --- versioning rule
$ver = static function (array $entry, string $lane = 'code'): ?bool {
    $d = tmpProject([$lane => $entry]);
    $c = loads($d, $lane);

    return $c ? $c['versioned'] : null;
};
$base = ['gate' => [], 'stage' => ['paths' => ['src']]];
check('code lane without key is not versioned', false === $ver($base));
check('explicit versioned true', true === $ver($base + ['versioned' => true]));
check('explicit versioned false beats metadata stage path', false === $ver(['gate' => [], 'stage' => ['paths' => ['src', 'metadata']], 'versioned' => false]));
check('explicit versioned false on the metadata lane', false === $ver(['gate' => [], 'stage' => ['paths' => ['metadata']], 'versioned' => false], 'metadata'));
check('stage path metadata makes a lane versioned', true === $ver(['gate' => [], 'stage' => ['paths' => ['src', 'metadata']]]));
check('stage path metadata/ makes a lane versioned', true === $ver(['gate' => [], 'stage' => ['paths' => ['metadata/']]]));
check('stage path inside metadata makes a lane versioned', true === $ver(['gate' => [], 'stage' => ['paths' => ['metadata/entities']]]));
check('a path that only starts with the word is not metadata', false === $ver(['gate' => [], 'stage' => ['paths' => ['metadata-tools']]]));
check('metadata lane with an entry and no key is versioned', true === $ver(['gate' => [], 'stage' => ['paths' => ['docs']]], 'metadata'));

// --- runner
$step = static fn (string $name, array $cmd): array => ['name' => $name, 'cmd' => $cmd];
$d = tmpProject(null);
$r = GateRunner::run($d, [$step('one', ['sh', '-c', 'echo first']), $step('two', ['sh', '-c', 'echo second'])], 'b', 'code');
check('all steps pass', $r['ok'] && null === $r['failedStep']);
check('steps run in order', strpos($r['output'], 'first') < strpos($r['output'], 'second'));
check('transcript names each gate', str_contains($r['output'], "-- gate: one\n") && str_contains($r['output'], "-- gate: two\n"));
check('transcript opens with the Validating line', str_starts_with($r['output'], "Validating session 'b' (lane: code) before commit..."));

$r = GateRunner::run($d, [$step('one', ['sh', '-c', 'echo ran']), $step('bad', ['sh', '-c', 'echo boom; exit 3']), $step('after', ['sh', '-c', 'echo should-not-run'])], 'b', 'code');
check('first failure stops the run', !$r['ok'] && 'bad' === $r['failedStep']);
check('later steps do not run', !str_contains($r['output'], 'should-not-run') && !str_contains($r['output'], '-- gate: after'));
check('failure output is kept', str_contains($r['output'], 'boom'));
check('abort line names the gate and the branch', str_contains($r['output'], "COMMIT ABORTED at gate 'bad'. Branch 'b' remains open and unchanged. main is untouched."));

$r = GateRunner::run($d, [$step('err', ['sh', '-c', 'echo to-stderr 1>&2; exit 1'])], 'b', 'code');
check('stderr is captured', str_contains($r['output'], 'to-stderr'));

file_put_contents($d.'/marker.txt', 'here');
$r = GateRunner::run($d, [$step('cwd', ['sh', '-c', 'cat marker.txt'])], 'b', 'code');
check('steps run from the project root', $r['ok'] && str_contains($r['output'], 'here'));

$r = GateRunner::run($d, [$step('missing', ['/no/such/binary-xyz'])], 'b', 'code');
check('a command that cannot start fails the gate', !$r['ok']);

$r = GateRunner::run($d, [], 'b', 'code');
check('no steps passes', $r['ok']);

// --- built-in validate step
$d = tmpProject(null);
$r = GateRunner::run($d, [['name' => 'validate', 'builtin' => 'validate']], 'b', 'metadata');
check('built-in validate fails on a project with no config', !$r['ok'] && str_contains($r['output'], 'COMMIT WOULD FAIL'));
check('built-in failure aborts the gate', str_contains($r['output'], "COMMIT ABORTED at gate 'validate'"));

$v = GateRunner::validateOnly($d);
check('validateOnly reports without a gate transcript', !$v['ok'] && !str_contains($v['output'], 'COMMIT ABORTED'));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
