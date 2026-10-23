<?php
// Offline proof of MetadataValidator: the rules of arch-core's validate.py,
// ported to PHP, accept and reject the same metadata. Each case writes a small
// metadata/ folder to a throwaway directory and runs the validator on it.
// No network, no secrets.
//
//     php tests/unit/test-validate.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/MetadataValidator.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/MetadataValidator.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once $ROOT.'/src/MetadataValidator.php';

use ArchMcp\MetadataValidator;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$base = sys_get_temp_dir().'/archvalidate'.getmypid();
$n = 0;
/**
 * Run the validator on a project whose metadata/ holds $files (path => content;
 * an array is JSON-encoded, a string is written as is). The valid config is
 * added unless the files already name one or $config is false.
 */
$run = static function (array $files, bool $config = true) use ($base, &$n): array {
    $d = $base.'/p'.(++$n);
    mkdir($d.'/metadata', 0775, true);
    if ($config && !isset($files['project.config.json'])) {
        $files['project.config.json'] = VALID_CONFIG;
    }
    foreach ($files as $rel => $content) {
        $f = $d.'/metadata/'.$rel;
        if (!is_dir(\dirname($f))) {
            mkdir(\dirname($f), 0775, true);
        }
        file_put_contents($f, is_array($content) ? json_encode($content) : $content);
    }

    return MetadataValidator::run($d);
};
$has = static fn (array $r, string $needle): bool => (bool) array_filter($r['errors'], static fn (string $e): bool => str_contains($e, $needle));

const HOST = ['provider' => 'cpanel', 'server_host' => 'h', 'account_username' => 'u', 'domain' => 'd', 'document_root' => 'r', 'deploy_path' => 'p'];
const VALID_CONFIG = [
    'project' => ['slug' => 'x'],
    'git' => ['remote' => 'git@github.com:o/x.git', 'default_branch' => 'main'],
    'environments' => ['production' => HOST, 'staging' => HOST],
    'deployment' => ['mechanism' => 'ssh-git-pull'],
    'capabilities' => ['runtime' => 'php', 'database' => 'sqlite'],
];
$cfg = static fn (array $over, array $drop = []): array => array_diff_key(array_merge(VALID_CONFIG, $over), array_flip($drop));
$todo = ['entity' => 'Todo', 'attributes' => ['id' => 'Guid', 'title' => 'String']];

echo "project config\n";
$r = $run([]);
check('a valid project passes, with the pass report', $r['ok'] && [] === $r['errors'] && str_contains($r['output'], 'COMMIT WOULD PASS'));
$r = $run([], false);
check('no project.config.json fails', !$r['ok'] && $has($r, 'project.config.json') && $has($r, 'missing.'));
$r = $run(['project.config.json' => '{bad']);
check('invalid JSON fails', !$r['ok'] && $has($r, 'invalid JSON'));
$r = $run(['project.config.json' => 'null']);
check('a config holding JSON null is skipped without error (as validate.py did)', $r['ok']);
$r = $run(['project.config.json' => '[]']);
check('a config that is a list fails', !$r['ok']);
$r = $run(['project.config.json' => $cfg([], ['project', 'git'])]);
check('missing top-level fields are named, sorted', $has($r, "missing required top-level field(s): ['git', 'project']"));
$r = $run(['project.config.json' => $cfg(['git' => ['remote' => 'x']])]);
check('git missing default_branch', $has($r, "git config missing field(s): ['default_branch']"));
$r = $run(['project.config.json' => $cfg(['hosting' => HOST])]);
check('hosting and environments together fail', $has($r, 'not both'));
$r = $run(['project.config.json' => $cfg([], ['environments'])]);
check('neither hosting nor environments fails', $has($r, 'missing hosting'));
$r = $run(['project.config.json' => $cfg(['hosting' => HOST], ['environments'])]);
check('the legacy single hosting block passes', $r['ok']);
$r = $run(['project.config.json' => $cfg(['hosting' => ['provider' => 'x']], ['environments'])]);
check('legacy hosting missing fields are named', $has($r, 'hosting config missing field(s)') && $has($r, "'deploy_path'"));
$r = $run(['project.config.json' => $cfg(['environments' => ['staging' => HOST]])]);
check('environments without production fails', $has($r, "at least a 'production' entry"));
$r = $run(['project.config.json' => $cfg(['environments' => ['production' => HOST, 'qa' => HOST]])]);
check('an unknown environment fails', $has($r, "unknown environment(s) ['qa']"));
$r = $run(['project.config.json' => $cfg(['environments' => ['production' => HOST, 'staging' => ['provider' => 'x']]])]);
check('a staging block missing fields fails', $has($r, 'environments.staging missing field(s)'));
$r = $run(['project.config.json' => $cfg(['environments' => ['production' => 'oops']])]);
check('an environment that is not an object fails', $has($r, 'environments.production must be an object'));
$r = $run(['project.config.json' => $cfg(['deployment' => ['mechanism' => 'ftp']])]);
check('an unknown deployment mechanism fails', $has($r, "deployment.mechanism 'ftp' not in allowed set"));
$r = $run(['project.config.json' => $cfg(['capabilities' => ['runtime' => 'ruby', 'database' => 'oracle']])]);
check('unknown runtime and database each fail', $has($r, "capabilities.runtime 'ruby'") && $has($r, "capabilities.database 'oracle'"));
$r = $run(['project.config.json' => $cfg(['capabilities' => ['runtime' => 'static-html', 'database' => 'none']])]);
check('static-html and database none pass', $r['ok']);
$r = $run(['project.config.json' => $cfg(['capabilities' => []])]);
check('capabilities as a list cannot be read: fails', !$r['ok']);
$r = $run(['project.config.json' => $cfg(['capabilities' => ['runtime' => ['php'], 'database' => 'none']])]);
check('an unhashable runtime fails instead of crashing', !$r['ok']);

echo "\nentities\n";
$r = $run(['entities/todo.json' => $todo]);
check('a valid entity passes', $r['ok']);
$r = $run(['entities/todo.json' => ['entity' => 'todo'] + $todo]);
check('lower-case name fails', $has($r, "must be PascalCase (e.g. 'Todo', not 'task')"));
$r = $run(["entities/todo.json" => "{\"entity\":\"Todo\\n\",\"attributes\":{\"a\":\"Guid\"}}"]);
check('a name with a trailing newline passes, as in Python', $r['ok']);
$r = $run(['entities/x.json' => ['attributes' => ['a' => 'Guid']]]);
check('missing entity field', $has($r, "missing required field 'entity'"));
$r = $run(['entities/x.json' => ['entity' => 5, 'attributes' => ['a' => 'Guid']]]);
check('a numeric entity name fails instead of crashing', !$r['ok']);
$r = $run(['entities/x.json' => ['entity' => 'Todo']]);
check('no attributes fails', $has($r, 'has no attributes (minProperties: 1)'));
$r = $run(['entities/x.json' => '{"entity":"Todo","attributes":{}}']);
check('empty attributes object fails', $has($r, 'has no attributes'));
$r = $run(['entities/x.json' => ['entity' => 'Todo', 'attributes' => ['a' => 'Text']]]);
check('an unknown attribute type fails, listing the allowed ones', $has($r, "attribute 'a' has type 'Text'") && $has($r, "'Percentage'"));
$r = $run(['entities/x.json' => ['entity' => 'Todo', 'attributes' => ['a' => 5]]]);
check('an attribute that is neither string nor object fails', $has($r, 'must be a type string or a computed-attribute object'));
$r = $run(['entities/x.json' => ['entity' => 'Todo', 'attributes' => ['a' => ['type' => 'Money', 'computed' => 'sum of lines']]]]);
check('a computed attribute passes', $r['ok']);
$r = $run(['entities/x.json' => ['entity' => 'Todo', 'attributes' => ['a' => ['type' => 'Money', 'computed' => '  ']]]]);
check('a blank derivation fails', $has($r, "must carry a non-empty 'computed'"));
$r = $run(['entities/x.json' => ['entity' => 'Todo', 'attributes' => ['a' => ['type' => 'Blob', 'computed' => 'x', 'extra' => 1]]]]);
check('computed: bad type and an unexpected key both fail', $has($r, "has unexpected key(s) ['extra']") && $has($r, "computed attribute 'a' has type 'Blob'"));
$r = $run(['entities/x.json' => $todo + ['invariants' => ['a rule', '  ']]]);
check('a blank invariant fails', $has($r, 'has an empty or invalid invariant'));
$r = $run(['entities/x.json' => $todo + ['invariants' => 'Rule']]);
check('invariants as a non-blank string passes (iterated by character, as in Python)', $r['ok']);
$r = $run(['entities/x.json' => $todo + ['invariants' => null]]);
check('invariants null fails instead of crashing', !$r['ok']);
$r = $run(['entities/x.json' => $todo + ['key' => ['id']]]);
check('a declared key passes', $r['ok']);
$r = $run(['entities/x.json' => $todo + ['key' => ['nope']]]);
check('an undeclared key attribute fails', $has($r, "key attribute 'nope' is not declared"));
$r = $run(['entities/x.json' => $todo + ['key' => []]]);
check('an empty key fails', $has($r, "'key' must be a non-empty array"));
$r = $run(['entities/x.json' => $todo + ['key' => 'id']]);
check('a key that is not a list fails', $has($r, "'key' must be a non-empty array"));
$r = $run(['entities/x.json' => $todo + ['key' => [['id']]]]);
check('an unhashable key item fails instead of crashing', !$r['ok']);

$rel = static fn (array $over): array => $todo + ['relations' => ['owner' => array_merge(['target' => 'Todo', 'cardinality' => 'one', 'via' => 'id'], $over)]];
$r = $run(['entities/x.json' => $rel([])]);
check('a valid relation passes', $r['ok']);
$r = $run(['entities/x.json' => $rel(['target' => 'Ghost'])]);
check('a relation to a missing entity fails', $has($r, "targets 'Ghost', which does not exist"));
$r = $run(['entities/x.json' => $rel(['cardinality' => 'few'])]);
check('a bad cardinality fails', $has($r, "cardinality must be 'one' or 'many', got 'few'"));
$r = $run(['entities/x.json' => $rel(['via' => 'zip'])]);
check('a via attribute not on the entity fails', $has($r, "via-attribute 'zip' is not declared"));
$r = $run(['entities/x.json' => $rel(['extra' => 1])]);
check('an unexpected relation key fails', $has($r, "has unexpected key(s) ['extra']"));
$r = $run(['entities/x.json' => $todo + ['relations' => ['o' => ['target' => 'Todo']]]]);
check('missing relation keys are named', $has($r, "is missing required key(s) ['cardinality', 'via']"));
$r = $run(['entities/x.json' => $todo + ['relations' => []]]);
check('empty relations fails', $has($r, "'relations' must be a non-empty object"));
$r = $run(['entities/a.json' => $todo + ['relations' => ['o' => ['target' => 'Board', 'cardinality' => 'many', 'via' => 'id']]], 'entities/b.json' => ['entity' => 'Board', 'attributes' => ['id' => 'Guid']]]);
check('a relation to an entity defined in a later file passes', $r['ok']);
$r = $run(['entities/x.json' => 'null']);
check('an entity file holding JSON null is skipped without error', $r['ok']);
$r = $run(['entities/x.json' => '[1]']);
check('an entity file that is a list fails', !$r['ok']);
$r = $run(['entities/x.json' => '{broken']);
check('an entity file with invalid JSON fails', $has($r, 'invalid JSON'));
$r = $run(['entities/.hidden.json' => '{broken']);
check('hidden files are checked too (as Python glob did)', !$r['ok']);
$r = $run(['entities/notes.txt' => '{broken']);
check('files that do not end in .json are ignored', $r['ok']);

echo "\ndomain models\n";
$ent = ['entities/todo.json' => $todo];
$r = $run($ent + ['domain/todo.domain.json' => ['entity' => 'Todo', 'events' => ['TodoCreated']]]);
check('a valid domain model passes', $r['ok']);
$r = $run($ent + ['domain/todo.domain.json' => ['entity' => 'Ghost', 'events' => ['GhostCreated']]]);
check('a domain model for a missing entity fails', $has($r, "references entity 'Ghost'"));
$r = $run($ent + ['domain/todo.domain.json' => ['events' => ['TodoCreated']]]);
check('a domain model with no entity fails', $has($r, "missing required field 'entity'"));
$r = $run($ent + ['domain/todo.domain.json' => ['entity' => 'Todo', 'events' => []]]);
check('no events fails', $has($r, 'declares no events (minItems: 1)'));
$r = $run($ent + ['domain/todo.domain.json' => ['entity' => 'Todo', 'events' => ['todoCreated']]]);
check('a lower-case event fails', $has($r, "event 'todoCreated' must be PascalCase"));
$r = $run($ent + ['domain/todo.domain.json' => ['entity' => 'Todo', 'events' => [7]]]);
check('a numeric event fails instead of crashing', !$r['ok']);
$r = $run($ent + ['domain/todo.txt' => 'x', 'domain/todo.json' => '{broken']);
check('only *.domain.json files are read', $r['ok']);

echo "\nworkflows\n";
$wf = ['workflow' => 'TodoWorkflow', 'states' => ['Open', 'Done'], 'transitions' => ['finish' => ['from' => 'Open', 'to' => 'Done']]];
$r = $run(['workflows/todo.workflow.json' => $wf]);
check('a valid workflow passes', $r['ok']);
$r = $run(['workflows/todo.workflow.json' => ['workflow' => 'Todo'] + $wf]);
check('a name that does not end in Workflow fails', $has($r, "must be PascalCase and end in 'Workflow'"));
$r = $run(['workflows/todo.workflow.json' => ['workflow' => 'TodoWorkflow', 'stateless' => true]]);
check('stateless passes', $r['ok']);
$r = $run(['workflows/todo.workflow.json' => $wf + ['stateless' => true]]);
check('stateless with states fails', $has($r, 'mutually exclusive'));
$r = $run(['workflows/todo.workflow.json' => ['states' => ['Open']] + $wf]);
check('fewer than two states fails', $has($r, 'fewer than 2 states'));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => []] + $wf]);
check('transitions as an empty list fails', !$r['ok'] && $has($r, 'declares no transitions'));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => new stdClass()] + $wf]);
check('transitions as {} fails once', 1 === count($r['errors']) && $has($r, 'declares no transitions'));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => ['go' => ['from' => 'Open', 'to' => 'Gone']]] + $wf]);
check('a transition to an undeclared state fails', $has($r, "to-state 'Gone' not in declared states"));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => ['go' => ['from' => 'Nowhere', 'to' => 'Done']]] + $wf]);
check('a transition from an undeclared state fails', $has($r, "from-state 'Nowhere' not in declared states"));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => ['create' => ['from' => 'None', 'to' => 'Open']]] + $wf]);
check('None is the implicit starting state', $r['ok']);
$r = $run(['workflows/todo.workflow.json' => ['transitions' => ['go' => ['to' => 'Open']]] + $wf]);
check('a transition with no from fails', $has($r, "from-state 'None' not in declared states") || $has($r, 'from-state'));
$r = $run(['workflows/todo.workflow.json' => ['transitions' => ['go' => 'Open']] + $wf]);
check('a transition that is not an object fails instead of crashing', !$r['ok']);

echo "\nintegrations\n";
$api = static fn (mixed $req, mixed $res): array => ['endpoints' => ['list' => ['request' => $req, 'response' => $res]]];
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['id' => 'Guid', 'title' => 'String'])]);
check('a valid integration passes ({} means no fields)', $r['ok']);
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['array' => ['id' => 'Guid']])]);
check('an {"array": shape} response passes', $r['ok']);
$r = $run(['integrations/a.api.json' => $api(new stdClass(), 'String')]);
check('a plain type name passes', $r['ok']);
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['rows' => ['array' => ['n' => ['m' => 'Integer']]]])]);
check('nested shapes pass', $r['ok']);
$r = $run(['integrations/a.api.json' => $api([], new stdClass())]);
check('[] is not an object shape: fails, "got list"', $has($r, 'got list'));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), 5)]);
check('a number is rejected, "got int"', $has($r, 'got int'));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), null)]);
check('null is rejected, "got NoneType"', $has($r, 'got NoneType'));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), true)]);
check('a boolean is rejected, "got bool"', $has($r, 'got bool'));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), 1.5)]);
check('a float is rejected, "got float"', $has($r, 'got float'));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['array' => 'Guid', 'x' => 'Guid'])]);
check('array mixed with other keys fails', $has($r, "mixes 'array' with other keys"));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['x' => 'Text'])]);
check('an unknown type in a field fails, with the field path', $has($r, "endpoint 'list' response.x has type 'Text'"));
$r = $run(['integrations/a.api.json' => $api(new stdClass(), ['array' => 'Text'])]);
check('an unknown type inside array fails, marked []', $has($r, 'response[] has type'));
$r = $run(['integrations/a.api.json' => ['endpoints' => ['list' => ['request' => new stdClass()]]]]);
check('an endpoint missing response fails', $has($r, "endpoint 'list' missing 'request' or 'response'"));
$r = $run(['integrations/a.api.json' => ['endpoints' => 'x']]);
check('endpoints that is a string fails instead of crashing', !$r['ok']);
$r = $run(['integrations/a.api.json' => ['endpoints' => ['list' => 'request response']]]);
check('an endpoint that is a string fails', !$r['ok']);
$r = $run(['integrations/a.api.json' => '{"endpoints":{}}']);
check('no endpoints fails once', 1 === count($r['errors']) && $has($r, 'declares no endpoints'));

echo "\nreport\n";
$r = $run(['entities/x.json' => ['entity' => 'a'] + $todo, 'workflows/t.workflow.json' => ['workflow' => 'bad', 'stateless' => true]]);
check('the fail report counts the errors and ends as validate.py did', 2 === count($r['errors']) && str_contains($r['output'], 'COMMIT WOULD FAIL — 2 error(s):') && str_contains($r['output'], '  ✗ ') && str_contains($r['output'], 'Branch remains untouched. main is unaffected. (constitution rule 5)'));
check('the report starts with the metadata path', str_starts_with($r['output'], 'ARCH metadata validation — '));
$r = MetadataValidator::run($base.'/nonexistent');
check('a project with no metadata folder fails (config missing)', !$r['ok'] && $has($r, 'missing.'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
