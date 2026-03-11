<?php

declare(strict_types=1);

namespace XOX;

final class GameManager
{
    /** @var array<int, Player> */
    private array $playersByConnection = [];

    /** @var array<string, Player> */
    private array $playersById = [];

    /** @var array<int, string> */
    private array $waitingQueue = [];

    /** @var array<string, GameRoom> */
    private array $rooms = [];

    public function registerConnection(SocketConnection $connection): void
    {
        $playerId = $this->generatePlayerId();
        $player = new Player($playerId, $connection);

        $this->playersByConnection[$connection->id()] = $player;
        $this->playersById[$playerId] = $player;

        $this->waitingQueue[] = $playerId;
        $player->send('waiting');

        $this->tryMatchPlayers();
    }

    public function handleMessage(SocketConnection $connection, string $rawMessage): void
    {
        $player = $this->playersByConnection[$connection->id()] ?? null;
        if ($player === null) {
            return;
        }

        try {
            /** @var array<string, mixed> $message */
            $message = json_decode($rawMessage, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $player->send('error', ['message' => 'Invalid JSON message.']);
            return;
        }

        $type = $message['type'] ?? null;
        if (!is_string($type)) {
            $player->send('error', ['message' => 'Message type is required.']);
            return;
        }

        $payload = $message['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        switch ($type) {
            case 'make_move':
                $this->handleMakeMove($player, $payload);
                break;

            case 'rematch':
                $this->handleRematch($player);
                break;

            default:
                $player->send('error', ['message' => 'Unknown message type.']);
                break;
        }
    }

    public function disconnect(SocketConnection $connection): void
    {
        $player = $this->playersByConnection[$connection->id()] ?? null;
        if ($player === null) {
            return;
        }

        unset($this->playersByConnection[$connection->id()], $this->playersById[$player->id]);
        $this->removeFromWaitingQueue($player->id);

        if ($player->roomId !== null) {
            $room = $this->rooms[$player->roomId] ?? null;
            if ($room !== null) {
                $opponent = $room->getOpponent($player->id);
                unset($this->rooms[$room->id]);

                if ($opponent !== null) {
                    $opponent->roomId = null;
                    $opponent->send('opponent_left');

                    $this->waitingQueue[] = $opponent->id;
                    $opponent->send('waiting');
                }
            }
        }

        $this->tryMatchPlayers();
    }

    /** @param array<string, mixed> $payload */
    private function handleMakeMove(Player $player, array $payload): void
    {
        $room = $this->roomForPlayer($player);
        if ($room === null) {
            $player->send('error', ['message' => 'No active game room.']);
            return;
        }

        $cell = $payload['cell'] ?? null;
        if (!is_int($cell)) {
            $player->send('error', ['message' => 'Cell must be an integer.']);
            return;
        }

        $result = $room->makeMove($player->id, $cell);
        if ($result['ok'] !== true) {
            $player->send('error', ['message' => $result['error'] ?? 'Invalid move.']);
            return;
        }

        foreach ($room->players() as $participant) {
            $participant->send('move_made', [
                'cell' => $cell,
                'symbol' => $player->symbol,
                'board' => $room->board(),
            ]);
        }

        if (($result['gameOver'] ?? false) === true) {
            $this->broadcastGameOver($room, $result);
            return;
        }

        $nextPlayerId = $result['nextTurnPlayerId'] ?? null;
        if (is_string($nextPlayerId)) {
            $nextPlayer = $room->getPlayer($nextPlayerId);
            $nextPlayer?->send('your_turn');
        }
    }

    private function handleRematch(Player $player): void
    {
        $room = $this->roomForPlayer($player);
        if ($room === null) {
            $player->send('error', ['message' => 'No active game room.']);
            return;
        }

        $isReady = $room->registerRematchVote($player->id);
        if (!$isReady) {
            $room->getOpponent($player->id)?->send('rematch_offered');
            return;
        }

        $room->startRematch();

        foreach ($room->players() as $participant) {
            $opponent = $room->getOpponent($participant->id);

            $participant->send('rematch_start');
            $participant->send('game_start', [
                'symbol' => $participant->symbol,
                'opponent' => $opponent?->id,
            ]);
        }

        $playerXId = $room->currentTurnPlayerId();
        $room->getPlayer($playerXId)?->send('your_turn');
    }

    private function tryMatchPlayers(): void
    {
        $this->waitingQueue = array_values(array_filter(
            $this->waitingQueue,
            fn (string $playerId): bool => isset($this->playersById[$playerId]) && $this->playersById[$playerId]->roomId === null
        ));

        while (count($this->waitingQueue) >= 2) {
            $firstPlayerId = array_shift($this->waitingQueue);
            $secondPlayerId = array_shift($this->waitingQueue);

            if ($firstPlayerId === null || $secondPlayerId === null) {
                return;
            }

            $firstPlayer = $this->playersById[$firstPlayerId] ?? null;
            $secondPlayer = $this->playersById[$secondPlayerId] ?? null;
            if ($firstPlayer === null || $secondPlayer === null) {
                continue;
            }

            $roomId = $this->generateRoomId();
            $room = new GameRoom($roomId, $firstPlayer, $secondPlayer);
            $this->rooms[$roomId] = $room;

            foreach ($room->players() as $player) {
                $opponent = $room->getOpponent($player->id);
                $player->send('game_start', [
                    'symbol' => $player->symbol,
                    'opponent' => $opponent?->id,
                ]);
            }

            $firstPlayer->send('your_turn');
        }
    }

    /** @param array{result?: string, winnerId?: string} $result */
    private function broadcastGameOver(GameRoom $room, array $result): void
    {
        if (($result['result'] ?? '') === 'draw') {
            foreach ($room->players() as $participant) {
                $participant->send('game_over', ['result' => 'draw']);
            }

            return;
        }

        $winnerId = $result['winnerId'] ?? null;
        foreach ($room->players() as $participant) {
            $participant->send('game_over', [
                'result' => $participant->id === $winnerId ? 'win' : 'lose',
            ]);
        }
    }

    private function roomForPlayer(Player $player): ?GameRoom
    {
        if ($player->roomId === null) {
            return null;
        }

        return $this->rooms[$player->roomId] ?? null;
    }

    private function removeFromWaitingQueue(string $playerId): void
    {
        $this->waitingQueue = array_values(array_filter(
            $this->waitingQueue,
            fn (string $id): bool => $id !== $playerId
        ));
    }

    private function generatePlayerId(): string
    {
        return 'p_' . bin2hex(random_bytes(4));
    }

    private function generateRoomId(): string
    {
        return 'room_' . bin2hex(random_bytes(4));
    }
}
