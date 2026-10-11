<?php
// Offline proof of Claude project ID handling (ClaudeProjectId): GUID shape,
// pasted project URLs, case folding, refusals, and the name label cleanup.
// Pure functions, no network, no files.
//
//     php tests/unit/test-claude-project-id.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ClaudeProjectId.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ClaudeProjectId.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ClaudeProjectId.php';

use ArchMcp\ClaudeProjectId;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$id = '123e4567-e89b-12d3-a456-426614174000';

echo "normalize(): accepted forms\n";
check('plain lower case GUID', $id === ClaudeProjectId::normalize($id));
check('upper case folds to lower case', $id === ClaudeProjectId::normalize(strtoupper($id)));
check('surrounding whitespace is trimmed', $id === ClaudeProjectId::normalize("  $id\n"));
check('claude.ai project URL gives its GUID', $id === ClaudeProjectId::normalize("https://claude.ai/project/$id"));
check('URL with a locale segment', $id === ClaudeProjectId::normalize("https://claude.ai/en/project/$id"));
check('URL with a trailing path or query', $id === ClaudeProjectId::normalize("https://claude.ai/project/$id/files?x=1") && $id === ClaudeProjectId::normalize("https://claude.ai/project/$id#top"));
check('upper case GUID inside a URL folds too', $id === ClaudeProjectId::normalize('https://claude.ai/project/'.strtoupper($id)));

echo "\nnormalize(): refused\n";
check('empty string', null === ClaudeProjectId::normalize(''));
check('whitespace only', null === ClaudeProjectId::normalize("  \n"));
check('too short', null === ClaudeProjectId::normalize('123e4567-e89b-12d3-a456'));
check('right length but not hex', null === ClaudeProjectId::normalize('123e4567-e89b-12d3-a456-42661417400z'));
check('no hyphens', null === ClaudeProjectId::normalize(str_replace('-', '', $id)));
check('all-zero GUID', null === ClaudeProjectId::normalize('00000000-0000-0000-0000-000000000000'));
check('a project name is not an ID', null === ClaudeProjectId::normalize('My project'));
check('another host', null === ClaudeProjectId::normalize("https://evil.example/project/$id"));
check('look-alike host', null === ClaudeProjectId::normalize("https://claude.ai.evil.example/project/$id"));
check('plain http', null === ClaudeProjectId::normalize("http://claude.ai/project/$id"));
check('GUID with trailing junk', null === ClaudeProjectId::normalize("$id extra"));
check('very long input', null === ClaudeProjectId::normalize(str_repeat('a', 400)));

echo "\nlabel()\n";
check('trimmed', 'My project' === ClaudeProjectId::label("  My project\n"));
check('control characters become spaces', 'a b c' === ClaudeProjectId::label("a\tb\nc"));
check('cut to 100 characters, multibyte safe', 100 === mb_strlen(ClaudeProjectId::label(str_repeat('é', 150))));
check('empty stays empty', '' === ClaudeProjectId::label(''));
check('invalid UTF-8 gives an empty label, not an error', '' === ClaudeProjectId::label("\xff\xfe"));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
