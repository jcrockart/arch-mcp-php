<?php
// Offline proof of the server instructions (ServerInstructions, ARCH-75): the
// switch gives the text only when the environment's key is exactly true and
// null otherwise, and the text cannot drift from the server: every tool it names
// is one public/index.php registers, it stays short, it carries its id and the
// "constitution wins" sentence, and it does not copy rule text or name a
// constitution file. Uses a temporary file only. No network.
//
//     php tests/unit/test-server-instructions.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ServerInstructions.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ServerInstructions.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ServerInstructions.php';

use ArchMcp\ServerInstructions;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "parse(): on only for an exact true\n";
check('staging on, production off: each environment reads its own key', true === ServerInstructions::parse('{"staging": true, "production": false}', 'staging') && false === ServerInstructions::parse('{"staging": true, "production": false}', 'production'));
check('a missing key is off', false === ServerInstructions::parse('{"staging": true}', 'production'));
foreach ([
    ['{"staging": "true"}', 'the string "true"'],
    ['{"staging": 1}', 'the number 1'],
    ['{"staging": null}', 'null'],
    ['{"staging": [true]}', 'a list'],
    ['{"staging": false}', 'false'],
    ['[]', 'an empty list'],
    ['true', 'a bare true'],
    ['not json', 'text that is not JSON'],
    ['', 'an empty string'],
    ['{"staging": true', 'truncated JSON'],
] as [$json, $label]) {
    check("$label: off", false === ServerInstructions::parse($json, 'staging'));
}
check('no file text (null): off', false === ServerInstructions::parse(null, 'staging'));
check('an unknown environment name is off', false === ServerInstructions::parse('{"staging": true}', 'dev'));

echo "\nforEnvironment(): the file\n";
$dir = sys_get_temp_dir().'/arch-instr-test-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);
$file = $dir.'/server-instructions.json';
check('no file: null (the server builds exactly as before)', null === ServerInstructions::forEnvironment('staging', $file));
file_put_contents($file, '{"staging": true, "production": false}');
check('staging on gives the text, production off gives null', ServerInstructions::TEXT === ServerInstructions::forEnvironment('staging', $file) && null === ServerInstructions::forEnvironment('production', $file));
file_put_contents($file, 'garbage');
check('a broken file turns everything off', null === ServerInstructions::forEnvironment('staging', $file) && null === ServerInstructions::forEnvironment('production', $file));
unlink($file);
mkdir($file);
check('a path that is a directory is off, not an error', null === ServerInstructions::forEnvironment('staging', $file));
rmdir($file);
rmdir($dir);

echo "\nthe text\n";
$text = ServerInstructions::TEXT;
check('it is short (under 1500 characters) and not empty', '' !== trim($text) && mb_strlen($text, 'UTF-8') < 1500);
check('it carries its guidance id, and the id constant matches', str_contains($text, '(id '.ServerInstructions::GUIDANCE_ID.')') && 'arch-g1' === ServerInstructions::GUIDANCE_ID);
check('it says the constitution wins and only points to it', str_contains($text, 'the constitution wins') && str_contains($text, 'only points to it'));
check('it is limited to chats about an ARCH project', str_contains($text, 'only when the person is working on an ARCH project'));
check('it says never to ask for the Claude project ID, a token or a key, and never to guess', str_contains($text, 'never ask the person for it') && str_contains($text, 'Never ask for, accept or repeat a password, token or key') && str_contains($text, 'Never guess'));
check('it says to stop when a tool refuses', str_contains($text, 'tell the person plainly and stop'));
check('it names no constitution file, so a rename cannot make it wrong', !str_contains($text, '.md') && !str_contains($text, 'CONSTITUTION'));
check('it does not mention any secret path, address or token value', !str_contains($text, '/home/') && !str_contains($text, 'http'));

echo "\ndrift guard: every tool the text names is registered\n";
$index = (string) file_get_contents($ROOT.'/public/index.php');
preg_match_all("/->addTool\(\[[^\]]*\],\s*'([A-Za-z_]+)'\)/", $index, $m);
$registered = $m[1];
check('public/index.php registers tools (the pattern found some)', \count($registered) > 30);
preg_match_all('/\b(?:arch_[a-z_]+|install_bootstrap|db_[a-z_]+)\b/', $text, $t);
$named = array_values(array_unique($t[0]));
check('the text names at least arch_project_info and arch_bootstrap_read', \in_array('arch_project_info', $named, true) && \in_array('arch_bootstrap_read', $named, true));
foreach ($named as $tool) {
    check("tool '$tool' is registered", \in_array($tool, $registered, true));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
