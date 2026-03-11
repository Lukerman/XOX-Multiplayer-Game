<?php

declare(strict_types=1);

namespace XOX;

final class GameRoom
{
    public readonly string $id;

    /** @var array<string, Player> */
    private array $players;

    /** @var array<int, string|null> */
    private array $board = [];
    private string $turnPlayerId;
    private bool $isFinished = false;

    /** @var array<string, bool> */
    private array $rematchVotes = [];

    public function __construct(string $id, Player $playerX, Player $playerO)
    {
        $this->id = $id;
        $this->players = [
            $playerX->id => $playerX,
            $playerO->id => $playerO,
        ];

        $playerX->symbol = 'X';
        $playerO->symbol = 'O';
        $playerX->roomId = $id;
        $playerO->roomId = $id;

        $this->resetBoard();
        $this->turnPlayerId = $playerX->id;
    }

    /** @return array<string, Player> */
    public function players(): array
    {
        return $this->players;
    }

    public function getPlayer(string $playerId): ?Player
    {
        return $this->players[$playerId] ?? null;
    }

    public function getOpponent(string $playerId): ?Player
    {
        foreach ($this->players as $id => $player) {
            if ($id !== $playerId) {
                return $player;
            }
        }

        return null;
    }

    public function currentTurnPlayerId(): string
    {
        return $this->turnPlayerId;
    }

    /** @return array<int, string|null> */
    public function board(): array
    {
        return $this->board;
    }

    /**
     * @return array{ok: bool, error?: string, gameOver?: bool, result?: string, winnerId?: string, nextTurnPlayerId?: string}
     */
    public function makeMove(string $playerId, int $cell): array
    {
        if ($this->isFinished) {
            return ['ok' => false, 'error' => 'Game is already over.'];
        }

        if (!isset($this->players[$playerId])) {
            return ['ok' => false, 'error' => 'Player is not in this room.'];
        }

        if ($this->turnPlayerId !== $playerId) {
            return ['ok' => false, 'error' => 'It is not your turn.'];
        }

        if ($cell < 0 || $cell > 8) {
            return ['ok' => false, 'error' => 'Cell must be between 0 and 8.'];
        }

        if ($this->board[$cell] !== null) {
            return ['ok' => false, 'error' => 'Cell is already occupied.'];
        }

        $symbol = $this->players[$playerId]->symbol;
        $this->board[$cell] = $symbol;

        $winnerSymbol = $this->winnerSymbol();
        if ($winnerSymbol !== null) {
            $this->isFinished = true;
            $winnerId = $this->playerIdBySymbol($winnerSymbol);

            return [
                'ok' => true,
                'gameOver' => true,
                'result' => 'win',
                'winnerId' => $winnerId,
            ];
        }

        if ($this->isDraw()) {
            $this->isFinished = true;

            return [
                'ok' => true,
                'gameOver' => true,
                'result' => 'draw',
            ];
        }

        $opponent = $this->getOpponent($playerId);
        if ($opponent !== null) {
            $this->turnPlayerId = $opponent->id;
        }

        return [
            'ok' => true,
            'gameOver' => false,
            'nextTurnPlayerId' => $this->turnPlayerId,
        ];
    }

    public function registerRematchVote(string $playerId): bool
    {
        if (!isset($this->players[$playerId])) {
            return false;
        }

        $this->rematchVotes[$playerId] = true;

        return count($this->rematchVotes) === 2;
    }

    public function startRematch(): void
    {
        foreach ($this->players as $player) {
            $player->symbol = $player->symbol === 'X' ? 'O' : 'X';
        }

        $this->resetBoard();
        $this->isFinished = false;
        $this->rematchVotes = [];

        $this->turnPlayerId = $this->playerIdBySymbol('X') ?? array_key_first($this->players);
    }

    private function resetBoard(): void
    {
        $this->board = array_fill(0, 9, null);
    }

    private function isDraw(): bool
    {
        foreach ($this->board as $cell) {
            if ($cell === null) {
                return false;
            }
        }

        return true;
    }

    private function winnerSymbol(): ?string
    {
        $lines = [
            [0, 1, 2],
            [3, 4, 5],
            [6, 7, 8],
            [0, 3, 6],
            [1, 4, 7],
            [2, 5, 8],
            [0, 4, 8],
            [2, 4, 6],
        ];

        foreach ($lines as [$a, $b, $c]) {
            if ($this->board[$a] !== null && $this->board[$a] === $this->board[$b] && $this->board[$b] === $this->board[$c]) {
                return $this->board[$a];
            }
        }

        return null;
    }

    private function playerIdBySymbol(string $symbol): ?string
    {
        foreach ($this->players as $player) {
            if ($player->symbol === $symbol) {
                return $player->id;
            }
        }

        return null;
    }
}
