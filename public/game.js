(() => {
  const statusText = document.getElementById('statusText');
  const boardEl = document.getElementById('board');
  const boardWrapEl = document.getElementById('boardWrap');
  const youSymbolEl = document.getElementById('youSymbol');
  const turnTextEl = document.getElementById('turnText');
  const overlayEl = document.getElementById('overlay');
  const overlayTitleEl = document.getElementById('overlayTitle');
  const overlayTextEl = document.getElementById('overlayText');
  const rematchBtn = document.getElementById('rematchBtn');
  const cellButtons = Array.from(document.querySelectorAll('.cell'));

  const wsProtocol = window.location.protocol === 'https:' ? 'wss' : 'ws';
  const wsUrl = `${wsProtocol}://${window.location.hostname}:8080`;

  let socket;
  let mySymbol = null;
  let board = Array(9).fill(null);
  let myTurn = false;
  let gameActive = false;

  function connect() {
    socket = new WebSocket(wsUrl);

    socket.addEventListener('open', () => {
      setStatus('Connected. Waiting for matchmaking...');
    });

    socket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      handleServerMessage(message.type, message.payload || {});
    });

    socket.addEventListener('close', () => {
      gameActive = false;
      myTurn = false;
      setTurnText('-');
      setStatus('Disconnected. Reconnecting...');
      setTimeout(connect, 1000);
    });

    socket.addEventListener('error', () => {
      setStatus('Network issue. Retrying...');
    });
  }

  function handleServerMessage(type, payload) {
    switch (type) {
      case 'waiting':
        gameActive = false;
        myTurn = false;
        setTurnText('-');
        setStatus('Waiting for opponent...');
        break;

      case 'game_start':
        mySymbol = payload.symbol || null;
        board = Array(9).fill(null);
        gameActive = true;
        myTurn = false;
        youSymbolEl.textContent = mySymbol || '-';
        setStatus('Match found. Game on.');
        setTurnText('Opponent');
        hideOverlay();
        renderBoard();
        boardWrapEl.classList.add('live');
        break;

      case 'your_turn':
        myTurn = true;
        setTurnText('You');
        setStatus('Your move.');
        renderBoard();
        break;

      case 'move_made':
        board = Array.isArray(payload.board) ? payload.board : board;
        myTurn = false;
        setTurnText('Opponent');
        renderBoard();
        break;

      case 'game_over':
        gameActive = false;
        myTurn = false;
        setTurnText('-');
        handleGameOver(payload.result);
        break;

      case 'rematch_offered':
        showOverlay('Rematch Requested', 'Your opponent wants a rematch.');
        rematchBtn.disabled = false;
        rematchBtn.textContent = 'Accept Rematch';
        break;

      case 'rematch_start':
        board = Array(9).fill(null);
        gameActive = true;
        myTurn = false;
        setStatus('Rematch started.');
        setTurnText('Opponent');
        hideOverlay();
        renderBoard();
        break;

      case 'opponent_left':
        gameActive = false;
        myTurn = false;
        setTurnText('-');
        showOverlay('Opponent Left', 'Your opponent disconnected. Waiting for a new player...');
        rematchBtn.style.display = 'none';
        setStatus('Opponent disconnected. Returning to lobby...');
        break;

      case 'error':
        setStatus(payload.message || 'Server error.');
        break;

      default:
        break;
    }
  }

  function handleGameOver(result) {
    rematchBtn.style.display = 'inline-flex';
    rematchBtn.disabled = false;
    rematchBtn.textContent = 'Request Rematch';

    if (result === 'win') {
      showOverlay('You Win', 'Great play. Want another round?');
      setStatus('Round ended: You won.');
      return;
    }

    if (result === 'lose') {
      showOverlay('You Lose', 'Close one. Want a rematch?');
      setStatus('Round ended: You lost.');
      return;
    }

    showOverlay('Draw', 'Nobody won this round. Rematch?');
    setStatus('Round ended: Draw.');
  }

  function setStatus(text) {
    statusText.textContent = text;
  }

  function setTurnText(text) {
    turnTextEl.textContent = text;
  }

  function showOverlay(title, body) {
    overlayTitleEl.textContent = title;
    overlayTextEl.textContent = body;
    overlayEl.classList.remove('hidden');
  }

  function hideOverlay() {
    overlayEl.classList.add('hidden');
    rematchBtn.style.display = 'inline-flex';
  }

  function renderBoard() {
    cellButtons.forEach((button, index) => {
      const value = board[index];
      button.textContent = value || '';
      button.disabled = !gameActive || !myTurn || value !== null;
      button.classList.toggle('occupied', value !== null);
    });
  }

  function send(type, payload = {}) {
    if (!socket || socket.readyState !== WebSocket.OPEN) {
      return;
    }

    socket.send(JSON.stringify({ type, payload }));
  }

  boardEl.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLButtonElement) || !target.dataset.cell) {
      return;
    }

    const cellIndex = Number.parseInt(target.dataset.cell, 10);
    if (!Number.isInteger(cellIndex)) {
      return;
    }

    if (!gameActive || !myTurn || board[cellIndex] !== null) {
      return;
    }

    send('make_move', { cell: cellIndex });
  });

  rematchBtn.addEventListener('click', () => {
    rematchBtn.disabled = true;
    rematchBtn.textContent = 'Waiting...';
    send('rematch');
  });

  renderBoard();
  connect();
})();
