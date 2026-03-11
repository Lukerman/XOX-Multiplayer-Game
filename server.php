<?php

declare(strict_types=1);

use XOX\GameManager;
use XOX\SocketConnection;

spl_autoload_register(static function (string $class): void {
    $prefix = 'XOX\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

/**
 * @return array{messages: array<int, string>, remaining: string, close: bool, pingPayloads: array<int, string>}
 */
function decodeWebSocketFrames(string $buffer): array
{
    $messages = [];
    $pingPayloads = [];
    $offset = 0;
    $length = strlen($buffer);
    $shouldClose = false;

    while (true) {
        if (($length - $offset) < 2) {
            break;
        }

        $byte1 = ord($buffer[$offset]);
        $byte2 = ord($buffer[$offset + 1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) === 0x80;
        $payloadLength = $byte2 & 0x7F;
        $headerLength = 2;

        if ($payloadLength === 126) {
            if (($length - $offset) < 4) {
                break;
            }

            $payloadLength = unpack('n', substr($buffer, $offset + 2, 2))[1];
            $headerLength = 4;
        } elseif ($payloadLength === 127) {
            if (($length - $offset) < 10) {
                break;
            }

            $parts = unpack('Nhigh/Nlow', substr($buffer, $offset + 2, 8));
            if ($parts['high'] !== 0) {
                $shouldClose = true;
                break;
            }

            $payloadLength = $parts['low'];
            $headerLength = 10;
        }

        if (!$masked) {
            $shouldClose = true;
            break;
        }

        $frameTotalLength = $headerLength + 4 + $payloadLength;
        if (($length - $offset) < $frameTotalLength) {
            break;
        }

        $mask = substr($buffer, $offset + $headerLength, 4);
        $maskedPayload = substr($buffer, $offset + $headerLength + 4, $payloadLength);

        $payload = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $payload .= $maskedPayload[$i] ^ $mask[$i % 4];
        }

        if ($opcode === 0x1) {
            $messages[] = $payload;
        } elseif ($opcode === 0x8) {
            $shouldClose = true;
        } elseif ($opcode === 0x9) {
            $pingPayloads[] = $payload;
        }

        $offset += $frameTotalLength;

        if ($shouldClose) {
            break;
        }
    }

    return [
        'messages' => $messages,
        'remaining' => substr($buffer, $offset),
        'close' => $shouldClose,
        'pingPayloads' => $pingPayloads,
    ];
}

/**
 * @param resource $socket
 */
function completeHandshake($socket, string $request): bool
{
    if (!preg_match('/Sec-WebSocket-Key:\\s*(.+)\\r\\n/i', $request, $matches)) {
        return false;
    }

    $clientKey = trim($matches[1]);
    $accept = base64_encode(sha1($clientKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    $response = "HTTP/1.1 101 Switching Protocols\r\n";
    $response .= "Upgrade: websocket\r\n";
    $response .= "Connection: Upgrade\r\n";
    $response .= "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

    return @fwrite($socket, $response) !== false;
}

$port = 8080;
$address = 'tcp://0.0.0.0:' . $port;
$serverSocket = @stream_socket_server($address, $errno, $errstr);
if ($serverSocket === false) {
    fwrite(STDERR, "Failed to create socket: {$errstr} ({$errno})" . PHP_EOL);
    exit(1);
}

stream_set_blocking($serverSocket, false);

$gameManager = new GameManager();

/** @var array<int, resource> $clientSockets */
$clientSockets = [];
/** @var array<int, SocketConnection> $connections */
$connections = [];
/** @var array<int, bool> $handshaken */
$handshaken = [];
/** @var array<int, string> $buffers */
$buffers = [];

echo "XOX WebSocket server listening on ws://localhost:{$port}" . PHP_EOL;

while (true) {
    $read = [$serverSocket];
    foreach ($clientSockets as $socket) {
        $read[] = $socket;
    }

    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 200000);
    if ($ready === false) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $serverSocket) {
            $newSocket = @stream_socket_accept($serverSocket, 0);
            if ($newSocket === false) {
                continue;
            }

            stream_set_blocking($newSocket, false);
            $connectionId = (int) $newSocket;

            $clientSockets[$connectionId] = $newSocket;
            $connections[$connectionId] = new SocketConnection($newSocket, $connectionId);
            $handshaken[$connectionId] = false;
            $buffers[$connectionId] = '';
            continue;
        }

        $connectionId = (int) $socket;
        if (!isset($connections[$connectionId])) {
            continue;
        }

        $chunk = @fread($socket, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                $gameManager->disconnect($connections[$connectionId]);
                unset($clientSockets[$connectionId], $handshaken[$connectionId], $buffers[$connectionId], $connections[$connectionId]);
                @fclose($socket);
            }

            continue;
        }

        $buffers[$connectionId] .= $chunk;

        if ($handshaken[$connectionId] === false) {
            $headerEnd = strpos($buffers[$connectionId], "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }

            $request = substr($buffers[$connectionId], 0, $headerEnd + 4);
            if (!completeHandshake($socket, $request)) {
                unset($clientSockets[$connectionId], $handshaken[$connectionId], $buffers[$connectionId], $connections[$connectionId]);
                @fclose($socket);
                continue;
            }

            $handshaken[$connectionId] = true;
            $buffers[$connectionId] = substr($buffers[$connectionId], $headerEnd + 4);
            $gameManager->registerConnection($connections[$connectionId]);
        }

        if ($buffers[$connectionId] === '') {
            continue;
        }

        $decoded = decodeWebSocketFrames($buffers[$connectionId]);
        $buffers[$connectionId] = $decoded['remaining'];

        foreach ($decoded['pingPayloads'] as $payload) {
            $connections[$connectionId]->sendPong($payload);
        }

        foreach ($decoded['messages'] as $message) {
            $gameManager->handleMessage($connections[$connectionId], $message);
        }

        if ($decoded['close']) {
            $gameManager->disconnect($connections[$connectionId]);
            unset($clientSockets[$connectionId], $handshaken[$connectionId], $buffers[$connectionId], $connections[$connectionId]);
            @fclose($socket);
        }
    }
}
