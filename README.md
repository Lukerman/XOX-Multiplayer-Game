# XOX Multiplayer Game

Real-time 1v1 Tic-Tac-Toe (XOX) built with PHP and WebSockets.

Players are auto-matched when they open the page. No login, no registration.

## Features

- Auto-connect and lobby join on page load
- 1v1 matchmaking (first player waits, second player starts game)
- Real-time move synchronization over WebSocket
- Automatic symbol assignment (`X` and `O`)
- Server-side move validation, win detection, and draw detection
- Rematch flow (both players must accept)
- Opponent disconnect handling with automatic return to lobby

## Tech Stack

- Backend: PHP 8.x
- Real-time: Ratchet (`cboden/ratchet`)
- Frontend: HTML, CSS, Vanilla JavaScript
- State storage: In-memory (inside long-running PHP server)
- Transport: JSON messages over WebSocket

## Project Structure

```text
.
├── server.php
├── src
│   ├── GameManager.php
│   ├── GameRoom.php
│   └── Player.php
├── public
│   ├── index.html
│   ├── game.js
│   └── style.css
├── composer.json
└── README.md
```

## Message Protocol

All messages use JSON format:

```json
{
	"type": "message_type",
	"payload": {}
}
```

### Server -> Client

- `waiting` - player is waiting in lobby
- `game_start` - payload: `{ symbol, opponent }`
- `your_turn` - player may act now
- `move_made` - payload: `{ cell, symbol, board }`
- `game_over` - payload: `{ result: "win" | "lose" | "draw" }`
- `opponent_left` - opponent disconnected
- `rematch_offered` - opponent requested rematch
- `rematch_start` - rematch confirmed by both players
- `error` - payload: `{ message }`

### Client -> Server

- `make_move` - payload: `{ cell: 0-8 }`
- `rematch` - payload: `{}`

## Setup

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Start WebSocket server

```bash
php server.php
```

Server listens on `ws://localhost:8080`.

### 3. Serve frontend

```bash
php -S localhost:3000 -t public/
```

### 4. Play

Open two tabs at `http://localhost:3000` and the players will be matched automatically.

## Notes

- Keep `server.php` running as a long-lived process.
- For production, run WebSocket server under a process manager (e.g. Supervisor/systemd).
- For secure transport (`wss://`), terminate TLS at a reverse proxy and forward to Ratchet.