<?php

namespace App\Health;

use App\Enum\ServiceState;

/**
 * One row of the "Estado de servicios" panel: what it is, whether it answers,
 * and how fast. `detail` is the technical subtitle (host, model, port).
 */
final readonly class ServiceStatus
{
    public function __construct(
        public string $key,
        public string $name,
        public string $detail,
        public ServiceState $state,
        public ?int $latencyMs = null,
        public ?string $message = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'detail' => $this->detail,
            'state' => $this->state->value,
            'latency_ms' => $this->latencyMs,
            'message' => $this->message,
        ];
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            key: (string) $d['key'],
            name: (string) $d['name'],
            detail: (string) $d['detail'],
            state: ServiceState::fromEngine($d['state'] ?? null),
            latencyMs: isset($d['latency_ms']) ? (int) $d['latency_ms'] : null,
            message: $d['message'] ?? null,
        );
    }

    /** "180 ms" / "1.2 s" — latency the way the panel prints it. */
    public function latencyLabel(): ?string
    {
        if ($this->latencyMs === null) {
            return null;
        }

        return $this->latencyMs >= 1000
            ? sprintf('%.1f s', $this->latencyMs / 1000)
            : sprintf('%d ms', $this->latencyMs);
    }
}
