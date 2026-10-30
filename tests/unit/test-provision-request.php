<?php
// Offline proof of the chat provisioning calls (ProvisionRequest, ARCH-74)
// against a fake Portal: one call per run and never a retry, Portal's refusals
// relayed byte for byte, the slug and notes cleaned before sending, a call with
// no usable answer reported as "may have started" for a request, and a `next`
// line added only on success. No network, no files.
//
//     php tests/unit/test-provision-request.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProvisionRequest.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProvisionRequest.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProvisionRequest.php';

use ArchMcp\PortalCaller;
use ArchMcp\ProvisionRequest;

final class FakeProvisionPortal implements PortalCaller
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $reply */
    public function __construct(public array $reply)
    {
    }

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $slug, $fields];

        return $this->reply;
    }
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$accepted = ['success' => true, 'accepted' => true, 'already_requested' => false, 'project_id' => 42, 'slug' => 'my-app', 'status' => 'provisioning', 'message' => 'Provisioning has started. It can take a few minutes; use status to follow it.'];

echo "request: success\n";
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app');
check('exactly one Portal call, action request, for the slug', 1 === \count($p->calls) && 'request' === $p->calls[0][0] && 'my-app' === $p->calls[0][1]);
check('no notes: no notes field is sent', [] === $p->calls[0][2]);
check("Portal's own keys come back unchanged", true === $r['success'] && 42 === $r['project_id'] && 'my-app' === $r['slug'] && 'provisioning' === $r['status'] && $accepted['message'] === $r['message'] && true === $r['accepted']);
check('a next line says to follow with arch_provision_status and not to ask again', str_contains($r['next'], 'arch_provision_status') && str_contains($r['next'], '15 to 30 seconds') && str_contains($r['next'], 'Do not request this project again'));
check('no notes_truncated key when nothing was cut', !isset($r['notes_truncated']));

$again = ['success' => true, 'accepted' => false, 'already_requested' => true, 'project_id' => 42, 'slug' => 'my-app', 'status' => 'provisioned', 'message' => 'This project was already requested. Use status to follow it.'];
$p = new FakeProvisionPortal($again);
$r = ProvisionRequest::request($p, 'my-app');
check('a repeat that is already provisioned points to install_bootstrap', true === $r['already_requested'] && str_contains($r['next'], 'install_bootstrap'));
$p = new FakeProvisionPortal(['success' => true, 'accepted' => true, 'project_id' => 1]);
$r = ProvisionRequest::request($p, 'my-app');
check('a success with no status gets no next line', !isset($r['next']) && 1 === $r['project_id']);

echo "\nrequest: Portal refusals come back exactly as sent, with one call and no retry\n";
$refusals = [
    'no_slots' => ['success' => false, 'code' => 'no_slots', 'error' => 'You have no auto-provisions left.', 'reason' => 'no_slots'],
    'not_eligible' => ['success' => false, 'code' => 'not_eligible', 'error' => 'This project cannot be auto-provisioned.', 'reason' => 'reserved'],
    'in_flight' => ['success' => false, 'code' => 'in_flight', 'error' => 'One of your projects is already being set up.'],
    'failed' => ['success' => false, 'code' => 'failed', 'error' => 'This project was requested before, but provisioning stopped. An admin has been told and can resume it. Do not request it again.', 'project_id' => 42, 'slug' => 'my-app', 'status' => 'provisioning_failed'],
    'invalid' => ['success' => false, 'code' => 'invalid', 'error' => 'The name must be 3 to 100 characters.'],
    'unauthorized' => ['success' => false, 'code' => 'unauthorized', 'error' => 'Sign in again.'],
    'error' => ['success' => false, 'code' => 'error', 'error' => 'Something went wrong; nothing was started.'],
];
foreach ($refusals as $code => $reply) {
    $p = new FakeProvisionPortal($reply);
    $r = ProvisionRequest::request($p, 'my-app', 'a note');
    check("$code: identical to Portal's reply, one call", $reply === $r && 1 === \count($p->calls));
}

echo "\nrequest: no usable answer\n";
$serverReply = ['success' => false, 'code' => 'server', 'error' => 'Could not reach Portal; nothing is known to have changed.'];
$p = new FakeProvisionPortal($serverReply);
$r = ProvisionRequest::request($p, 'my-app');
check('code server: it MAY have started, do not request again, read status', false === $r['success'] && 'server' === $r['code'] && true === $r['may_have_started'] && str_contains($r['error'], 'MAY have started') && str_contains($r['error'], 'arch_provision_status') && str_contains($r['error'], 'Do not request again'));
check("the old 'nothing is known to have changed' wording is not used", !str_contains(json_encode($r), 'nothing is known'));
check('still one call, no retry', 1 === \count($p->calls));
$p = new FakeProvisionPortal(['success' => false]);
$r = ProvisionRequest::request($p, 'my-app');
check('a failure with no code is treated the same way', true === ($r['may_have_started'] ?? false) && 1 === \count($p->calls));
$p = new FakeProvisionPortal(['success' => false, 'code' => '']);
check('an empty code is treated the same way', true === (ProvisionRequest::request($p, 'my-app')['may_have_started'] ?? false));

echo "\nrequest: the slug\n";
$p = new FakeProvisionPortal($accepted);
ProvisionRequest::request($p, "  my-app \n");
check('trimmed before sending; case is left to Portal', 'my-app' === $p->calls[0][1]);
$p = new FakeProvisionPortal($accepted);
ProvisionRequest::request($p, 'My-App');
check('upper case is sent as given (Portal lowercases it)', 'My-App' === $p->calls[0][1]);
foreach ([['', 'empty'], ["  \n", 'blank'], [str_repeat('a', 201), 'over 200 bytes'], ["my\x00app", 'a NUL byte'], ["my\napp", 'a line break inside']] as [$slug, $label]) {
    $p = new FakeProvisionPortal($accepted);
    $r = ProvisionRequest::request($p, $slug);
    check("$label: refused before any call, code invalid, rule stated", [] === $p->calls && false === $r['success'] && 'invalid' === $r['code'] && str_contains($r['error'], '3 to 100 characters'));
}
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'ab');
check('a too-short slug is sent: the length rule is Portal\'s, and its text comes back', 1 === \count($p->calls));

echo "\nrequest: the notes\n";
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app', "  A family site\0 for Christmas \n");
check('trimmed, NUL removed', "A family site for Christmas" === $p->calls[0][2]['notes']);
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app', "  \n ");
check('blank notes: no notes field', [] === $p->calls[0][2]);
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app', str_repeat('a', 2000));
check('exactly 2000 characters: sent whole, not flagged', 2000 === mb_strlen($p->calls[0][2]['notes'], 'UTF-8') && !isset($r['notes_truncated']));
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app', str_repeat('a', 2005));
check('2005 characters: cut to 2000 and the reply says so', 2000 === mb_strlen($p->calls[0][2]['notes'], 'UTF-8') && true === $r['notes_truncated']);
$p = new FakeProvisionPortal($accepted);
$r = ProvisionRequest::request($p, 'my-app', str_repeat("\u{00e9}", 2001));
check('cut on a character boundary, never inside one', 2000 === mb_strlen($p->calls[0][2]['notes'], 'UTF-8') && mb_check_encoding($p->calls[0][2]['notes'], 'UTF-8') && true === $r['notes_truncated']);
$p = new FakeProvisionPortal($accepted);
ProvisionRequest::request($p, 'my-app', "ok \xC3\x28 bad");
check('invalid UTF-8 is scrubbed, not sent as is', mb_check_encoding($p->calls[0][2]['notes'], 'UTF-8'));
$p = new FakeProvisionPortal($refusals['invalid']);
$r = ProvisionRequest::request($p, 'my-app', str_repeat('a', 3000));
check('a refusal is not decorated with notes_truncated', $refusals['invalid'] === $r);

echo "\nstatus\n";
$statusBody = ['success' => true, 'project_id' => 42, 'slug' => 'my-app', 'status' => 'provisioning', 'auto_provisioned' => true, 'steps' => [['step' => 'repo_created', 'status' => 'done']], 'steps_done' => 3, 'steps_total' => 9, 'failed_step' => null, 'your_slots' => ['remaining' => 1, 'unlimited' => false], 'message' => 'Provisioning is in progress.'];
$p = new FakeProvisionPortal($statusBody);
$r = ProvisionRequest::status($p, ' my-app ');
check('one Portal call, action status, no extra fields, slug trimmed', 1 === \count($p->calls) && 'status' === $p->calls[0][0] && 'my-app' === $p->calls[0][1] && [] === $p->calls[0][2]);
check("Portal's keys unchanged, plus next to keep following", 3 === $r['steps_done'] && null === $r['failed_step'] && 1 === $r['your_slots']['remaining'] && str_contains($r['next'], '15 to 30 seconds'));
$p = new FakeProvisionPortal(['success' => true, 'status' => 'provisioned', 'steps_done' => 9, 'steps_total' => 9, 'failed_step' => null, 'message' => 'Provisioning has finished.'] + $statusBody);
$r = ProvisionRequest::status($p, 'my-app');
check('provisioned: next is install_bootstrap', 'provisioned' === $r['status'] && str_contains($r['next'], 'install_bootstrap'));
$p = new FakeProvisionPortal(['status' => 'provisioning_failed', 'failed_step' => 'staging_symlink'] + $statusBody);
$r = ProvisionRequest::status($p, 'my-app');
check('failed: next says stop and not to request again', str_contains($r['next'], 'stop') || str_contains($r['next'], 'Stop'));
check('failed: failed_step passed through', 'staging_symlink' === $r['failed_step']);
$notFound = ['success' => false, 'code' => 'not_found', 'error' => 'No such project, or it is not yours to see.'];
$p = new FakeProvisionPortal($notFound);
check('not_found comes back exactly as sent', $notFound === ProvisionRequest::status($p, 'someone-elses'));
$p = new FakeProvisionPortal($serverReply);
$r = ProvisionRequest::status($p, 'my-app');
check('no usable answer: says nothing was changed, no may_have_started', false === $r['success'] && 'server' === $r['code'] && !isset($r['may_have_started']) && str_contains($r['error'], 'changed nothing'));
$p = new FakeProvisionPortal($statusBody);
$r = ProvisionRequest::status($p, '');
check('empty slug: refused before any call', [] === $p->calls && 'invalid' === $r['code']);

echo "\nsafety\n";
$p = new FakeProvisionPortal($accepted);
$all = json_encode([ProvisionRequest::request($p, 'my-app', 'note'), ProvisionRequest::status($p, 'my-app')]);
check('no token field in anything sent or returned', !str_contains($all, 'access_token') && !str_contains(json_encode($p->calls), 'access_token'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
