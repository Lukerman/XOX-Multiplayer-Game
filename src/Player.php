<?php

declare(strict_types=1);

namespace XOX;

use Ratchet\ConnectionInterface;

final class Player
{
    public readonly string $id;
    public readonly ConnectionInterface $connection;
    public string $symbol = '';
    public ?string $roomId = null;

    public function __construct(string $id, ConnectionInterface $connection)
    {
        $this->id = $id;
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $type, array $payload = []): void
    {
        $this->connection->send(json_encode([
            'type' => $type,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }
}
