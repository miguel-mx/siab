<?php

namespace App\Engine;

/**
 * Result of pressing "Arrancar motor": what to tell the administrator, and — when
 * it did not come up — the tail of the engine's own log, which is the only place
 * the real reason (a missing venv, a port already taken) is written down.
 */
final readonly class EngineStartOutcome
{
    private function __construct(
        public bool $ok,
        public string $message,
        public string $logTail = '',
    ) {
    }

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message, string $logTail = ''): self
    {
        return new self(false, $message, $logTail);
    }
}
