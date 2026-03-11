<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use XOX\GameManager;

final class GameServer implements MessageComponentInterface
{
    private GameManager $gameManager;

    public function __construct()
    {
        $this->gameManager = new GameManager();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->gameManager->registerConnection($conn);
    }

    public function onMessage(ConnectionInterface $from, $message): void
    {
        $this->gameManager->handleMessage($from, (string) $message);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->gameManager->disconnect($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        fwrite(STDERR, "WebSocket error: {$e->getMessage()}" . PHP_EOL);
        $conn->close();
        $this->gameManager->disconnect($conn);
    }
}

$port = 8080;
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new GameServer()
        )
    ),
    $port
);

echo "XOX WebSocket server listening on ws://localhost:{$port}" . PHP_EOL;
$server->run();
