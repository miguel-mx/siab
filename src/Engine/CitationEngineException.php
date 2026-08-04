<?php

namespace App\Engine;

/**
 * Thrown when the citation engine is unreachable or answers with an error.
 * `statusCode` is the engine's HTTP status when there was a response, or null
 * for transport-level failures (connection refused, timeout).
 */
final class CitationEngineException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $detail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
