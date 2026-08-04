<?php

namespace App\Enum;

/**
 * Health of one dependency shown in the dashboard's service panel. Values match
 * the strings the Python engine reports (app/health.py), plus UNKNOWN for what we
 * cannot probe ourselves — the engine relays OpenAlex/Ollama, so when the engine
 * is down their real state is simply not known.
 */
enum ServiceState: string
{
    case OK = 'ok';
    case DEGRADED = 'degraded';
    case DOWN = 'down';
    case NOT_CONFIGURED = 'not_configured';
    case UNKNOWN = 'unknown';

    public static function fromEngine(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::UNKNOWN;
    }

    /** Spanish label for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::OK => 'Operativo',
            self::DEGRADED => 'Degradado',
            self::DOWN => 'Sin conexión',
            self::NOT_CONFIGURED => 'No configurado',
            self::UNKNOWN => 'Sin datos',
        };
    }

    /** Modifier for `.status-dot` and the status text (see assets/styles/app.css). */
    public function cssClass(): string
    {
        return match ($this) {
            self::OK => 'ok',
            self::DEGRADED => 'warn',
            self::DOWN => 'down',
            self::NOT_CONFIGURED, self::UNKNOWN => 'off',
        };
    }

    /** Whether this dependency is usable right now. Optional sources are not "broken". */
    public function isUsable(): bool
    {
        return $this === self::OK || $this === self::DEGRADED;
    }

    /** Something a human should act on (excludes deliberately unconfigured sources). */
    public function needsAttention(): bool
    {
        return $this === self::DOWN || $this === self::DEGRADED;
    }
}
