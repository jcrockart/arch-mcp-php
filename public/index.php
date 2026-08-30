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

$container = new Container();
$container->set(LoggerInterface::class, $logger);

// The SDK's default session store (InMemorySessionStore) keeps session
// state in a PHP array in process memory. On this host, every HTTP
// request is served by a fresh, stateless PHP-FPM/LiteSpeed process —
// nothing persists between requests — so an in-memory store can never
// actually retain a session across two separate calls. FileSessionStore
// persists sessions to disk instead, matching the same "durable,
// process-independent state" principle already applied to the git repo
// itself (Codegen CLI Design §7.3).
$sessionDir = dirname(__DIR__).'/var/sessions';

$server = Server::builder()
    ->setServerInfo('arch-collab', '0.1.0')
    ->setLogger($logger)
    ->setContainer($container)
    ->setSession(new FileSessionStore($sessionDir))
    ->addTool([ArchTools::class, 'archSessionStart'], 'arch_session_start')
    ->addTool([ArchTools::class, 'archCodegenPreview'], 'arch_codegen_preview')
    ->addTool([ArchTools::class, 'archSessionCommit'], 'arch_session_commit')
    ->addTool([ArchTools::class, 'archSessionDiscard'], 'arch_session_discard')
    ->addTool([ArchTools::class, 'archSessionStatus'], 'arch_session_status')
    ->addTool([ArchTools::class, 'archSessionWriteFile'], 'arch_session_write_file')
    ->addTool([ArchTools::class, 'archSessionReadFile'], 'arch_session_read_file')
    ->addTool([ArchTools::class, 'archSessionListFiles'], 'arch_session_list_files')
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
