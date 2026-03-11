<?php

declare(strict_types=1);

namespace XOX;

final class SocketConnection
{
    /** @var resource */
    private $socket;
    private int $id;

    /**
     * @param resource $socket
     */
    public function __construct($socket, int $id)
    {
        $this->socket = $socket;
        $this->id = $id;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function send(string $text): void
    {
        $frame = $this->encodeFrame($text, 0x1);
        @fwrite($this->socket, $frame);
    }

    public function sendPong(string $payload = ''): void
    {
        $frame = $this->encodeFrame($payload, 0xA);
        @fwrite($this->socket, $frame);
    }

    public function close(): void
    {
        @fclose($this->socket);
    }

    private function encodeFrame(string $payload, int $opcode): string
    {
        $finAndOpcode = 0x80 | ($opcode & 0x0F);
        $length = strlen($payload);

        if ($length <= 125) {
            return chr($finAndOpcode) . chr($length) . $payload;
        }

        if ($length <= 65535) {
            return chr($finAndOpcode) . chr(126) . pack('n', $length) . $payload;
        }

        return chr($finAndOpcode) . chr(127) . pack('N2', 0, $length) . $payload;
    }
}
