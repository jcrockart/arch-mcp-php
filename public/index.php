<?php

/*
 * ARCH Agent-Connection MCP server — public HTTP entrypoint.
 *
 * Runs under normal Apache/PHP-FPM request handling (no persistent
 * daemon needed — Apache is already the always-on process, the same way
 * it already serves the rest of this host's sites). Each request is
 * handled by StreamableHttpTransport exactly once, then this script
 * exits, same as any other PHP page.
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

// ArchProfiles is new in the 2026-09-01 token-scoping change. Required
// explicitly rather than relying on the autoloader: vendor/ on this host
// was built elsewhere and uploaded, so if its autoloader was generated
// with --classmap-authoritative it will not see a class added later, and
// composer may not be installed on the host to regenerate it. PSR-4
// would find it anyway; this just removes a failure mode from the
// deploy. Harmless either way (require_once).
require_once dirname(__DIR__).'/src/ArchProfiles.php';

use ArchMcp\ArchProfiles;
use ArchMcp\ArchTools;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Capability\Registry\Container;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Http\Discovery\Psr17Factory;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

function archMcpLogger(): LoggerInterface
{
    static $logger = null;
    if (null !== $logger) {
        return $logger;
    }

    return $logger = new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $line = sprintf('[%s] %s', strtoupper((string) $level), $message);
            if ([] !== $context) {
                $line .= ' '.json_encode($context, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            error_log($line);
        }
    };
}

set_exception_handler(static function (\Throwable $t): void {
    archMcpLogger()->critical('Uncaught exception: '.$t->getMessage(), ['exception' => $t]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'internal_error']);
});

$logger = archMcpLogger();

// ---------------------------------------------------------------------
// Token gate. Added 2026-09-01, addressing scheme revised 2026-09-02.
//
// Before this, the server had no authentication of any kind: anyone who
// knew the URL could call all 11 tools against arch-collab-core,
// including both write lanes (Codegen CLI Design §9 item 5). Now the
// token — in the x-api-key header — selects a profile that decides the
// root directory, the available lanes, and whether the session tools
// exist at all for this caller.
//
// The URL carries the ADDRESS, which is an identifier and not a
// credential: /project/<name> for a dedicated space, or
// /group/<name>/<sub...> for a shared space in which each sub-project
// is a separate subtree under one group token. Both must agree with the
// token's own profile or the request is refused — a connector pointed
// at the wrong address with a valid token must fail loudly rather than
// succeed quietly against the wrong tree.
//
// 404 rather than 401/403, and before any MCP machinery starts: an
// unauthenticated caller should not be able to distinguish "wrong
// token" from "nothing here", nor reach anything that would tell them
// what this endpoint is. The token is never written to the log — only
// the resolved address is.
// ---------------------------------------------------------------------
// One call makes the whole authorisation decision: token (header, or
// path during migration), profile lookup, address match, and — for a
// group — resolving the sub-project subtree.
[$profile, $authVia] = ArchProfiles::resolveRequest($_SERVER);

if (null === $profile) {
    // 'via' records how the caller TRIED to authenticate, never what with.
    $logger->warning('Rejected MCP request', ['via' => $authVia]);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    exit;
}

// 'via' is the migration signal: once this never reads 'path' again,
// flip ArchProfiles::ALLOW_PATH_TOKEN to false and the URL stops being
// a credential.
$logger->info('MCP request authorised', ['address' => $profile['address'], 'via' => $authVia]);

$container = new Container();
$container->set(LoggerInterface::class, $logger);

// Hand the SDK a pre-built ArchTools carrying this caller's profile,
// rather than letting the container construct a default one.
$container->set(ArchTools::class, new ArchTools($logger, $profile));

// The SDK's default session store (InMemorySessionStore) keeps session
// state in a PHP array in process memory. On this host, every HTTP
// request is served by a fresh, stateless PHP-FPM/LiteSpeed process —
// nothing persists between requests — so an in-memory store can never
// actually retain a session across two separate calls. FileSessionStore
// persists sessions to disk instead, matching the same "durable,
// process-independent state" principle already applied to the git repo
// itself (Codegen CLI Design §7.3).
//
// Session state is additionally partitioned per profile: a session
// belongs to one token's tree and must never be visible to another.
// Partition by ADDRESS, not label: two sub-projects of one group share a
// profile and a token, but must never share session state.
$sessionDir = dirname(__DIR__).'/var/sessions/'.hash('sha256', $profile['address']);

$builder = Server::builder()
    ->setServerInfo('arch-mcp ('.$profile['address'].')', '0.3.0')
    ->setLogger($logger)
    ->setContainer($container)
    ->setSession(new FileSessionStore($sessionDir));

// Tool registration is now per-profile. This filters what the caller is
// TOLD it can do; ArchTools::resolveScopedPath independently enforces
// what it can actually reach, because an unlisted tool can still be
// invoked by name. Both layers are required — neither alone is enough.
if ($profile['session_tools']) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSessionStart'], 'arch_session_start')
        ->addTool([ArchTools::class, 'archCodegenPreview'], 'arch_codegen_preview')
        ->addTool([ArchTools::class, 'archSessionCommit'], 'arch_session_commit')
        ->addTool([ArchTools::class, 'archSessionDiscard'], 'arch_session_discard')
        ->addTool([ArchTools::class, 'archSessionStatus'], 'arch_session_status');
}

if ($profile['session_tools'] && isset($profile['lanes']['metadata'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSessionWriteFile'], 'arch_session_write_file')
        ->addTool([ArchTools::class, 'archSessionReadFile'], 'arch_session_read_file')
        ->addTool([ArchTools::class, 'archSessionListFiles'], 'arch_session_list_files');
}

if (isset($profile['lanes']['core'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitStatus'], 'arch_core_git_status')
        ->addTool([ArchTools::class, 'archCoreGitDiff'], 'arch_core_git_diff')
        ->addTool([ArchTools::class, 'archCoreGitLog'], 'arch_core_git_log')
        ->addTool([ArchTools::class, 'archCoreGitShow'], 'arch_core_git_show')
        ->addTool([ArchTools::class, 'archCoreGitFetch'], 'arch_core_git_fetch');
}

// Added 2026-09-12, alongside item 3 ("pull to production"). Deliberately
// a SEPARATE conditional block from the unconditional core-lane block
// above, gated on the profile's pull_allowed flag (set only via
// mint-tokens.py --allow-pull) — a profile without the flag doesn't even
// see this tool exists, matching the "advertised list is a filter,
// runtime check is the real control, both required" principle already in
// effect for every other lane above. The runtime check lives in
// ArchTools::archCoreGitPullFastForward() itself ($this->pullAllowed),
// so this gate is belt-and-suspenders, not the only enforcement.
if (isset($profile['lanes']['core']) && ($profile['pull_allowed'] ?? false)) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitPullFastForward'], 'arch_core_git_pull');
}

// Added 2026-09-13, item 4 ("push to origin"). Same pattern as the
// pull_allowed block above: a profile without push_allowed doesn't even
// see this tool exists. Setting push_allowed alone does nothing live
// until a deploy key with write access to that checkout's GitHub origin
// is also configured on the host -- this file and ArchTools.php never
// create, store, or read one.
if (isset($profile['lanes']['core']) && ($profile['push_allowed'] ?? false)) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitPushOrigin'], 'arch_core_git_push');
}

// Added 2026-08-31, deployed with James live at the terminal after an
// overnight draft-and-test cycle (not shipped unattended — see Codegen
// CLI Design §8 for why). Deliberately separate name prefix
// (arch_site_* vs arch_session_*) so the tool list itself documents
// that these aren't session-gated and don't touch metadata/.
if (isset($profile['lanes']['site'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSiteWriteFile'], 'arch_site_write_file')
        ->addTool([ArchTools::class, 'archSiteReadFile'], 'arch_site_read_file')
        ->addTool([ArchTools::class, 'archSiteListFiles'], 'arch_site_list_files');
}

$server = $builder
    ->setCapabilities(new ServerCapabilities(
        tools: true,
        toolsListChanged: false,
        resources: false,
        resourcesSubscribe: false,
        resourcesListChanged: false,
        prompts: false,
        promptsListChanged: false,
        logging: false,
        completions: false,
    ))
    ->build();

$psr17 = new Psr17Factory();
$transport = new StreamableHttpTransport(
    $psr17->createServerRequestFromGlobals(),
    logger: $logger,
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
