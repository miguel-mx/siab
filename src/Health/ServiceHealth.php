<?php

namespace App\Health;

use App\Enum\ServiceState;

/**
 * The whole service panel: every dependency's status plus when it was probed.
 */
final readonly class ServiceHealth
{
    /** @param list<ServiceStatus> $services */
    public function __construct(
        public array $services,
        public \DateTimeImmutable $checkedAt,
    ) {
    }

    public function get(string $key): ?ServiceStatus
    {
        foreach ($this->services as $service) {
            if ($service->key === $key) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The subset shown as pills in the top bar — the three services a run needs.
     *
     * @return list<ServiceStatus>
     */
    public function headline(): array
    {
        return array_values(array_filter(array_map(
            fn (string $key) => $this->get($key),
            ['engine', 'openalex', 'ollama'],
        )));
    }

    /**
     * Worst state across the services that matter (optional/unknown ones excluded),
     * so the header can show one overall dot.
     */
    public function worstState(): ServiceState
    {
        $worst = ServiceState::OK;
        foreach ($this->services as $service) {
            if ($service->state === ServiceState::DOWN) {
                return ServiceState::DOWN;
            }
            if ($service->state === ServiceState::DEGRADED) {
                $worst = ServiceState::DEGRADED;
            }
        }

        return $worst;
    }

    /** @return list<ServiceStatus> */
    public function needingAttention(): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (ServiceStatus $s) => $s->state->needsAttention(),
        ));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'services' => array_map(static fn (ServiceStatus $s) => $s->toArray(), $this->services),
            'checked_at' => $this->checkedAt->format(\DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            services: array_map(ServiceStatus::fromArray(...), $d['services'] ?? []),
            checkedAt: new \DateTimeImmutable($d['checked_at'] ?? 'now'),
        );
    }
}
