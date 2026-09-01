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
// Token gate. Added 2026-09-01. DRAFT — not deployed.
//
// Before this, the server had no authentication of any kind: anyone who
// knew the URL could call all 11 tools against arch-collab-core,
// including both write lanes (Codegen CLI Design §9 item 5). The URL is
// now /t/<token>/ and the token selects a profile that decides the root
// directory, the available lanes, and whether the session tools exist
// at all for this caller.
//
// 404 rather than 401/403, and before any MCP machinery starts: an
// unauthenticated caller should not be able to distinguish "wrong
// token" from "nothing here", nor reach anything that would tell them
// what this endpoint is. The token is never written to the log — only
// the resolved label is.
// ---------------------------------------------------------------------
$profile = ArchProfiles::resolve(
    ArchProfiles::extractToken($_SERVER['REQUEST_URI'] ?? '')
);

if (null === $profile) {
    $logger->warning('Rejected MCP request with no valid token profile');
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    exit;
}

$logger->info('MCP request authorised', ['profile' => $profile['label']]);

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
$sessionDir = dirname(__DIR__).'/var/sessions/'.hash('sha256', $profile['label']);

$builder = Server::builder()
    ->setServerInfo('arch-mcp ('.$profile['label'].')', '0.2.0')
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
