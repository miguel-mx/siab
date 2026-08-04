<?php

namespace App\Enum;

/**
 * The engine-facing settings an administrator can edit.
 *
 * These are stored in SIAB's database and sent to the Python engine per request, so
 * changing one takes effect on the next analysis without restarting uvicorn. The
 * engine's own .env only supplies fallbacks for when SIAB has never set a value.
 */
enum SettingKey: string
{
    case ENGINE_URL = 'engine_url';
    case OLLAMA_BASE = 'ollama_base';
    case OLLAMA_MODEL = 'ollama_model';
    case MAX_WORKS = 'max_works';
    case MAX_CITING_PER_WORK = 'max_citing_per_work';
    case COUNT_TOLERANCE = 'count_tolerance';
    case SCOPUS_API_KEY = 'scopus_api_key';
    case WOS_API_KEY = 'wos_api_key';

    /**
     * Secrets are encrypted at rest and never sent back to the browser — the form
     * shows whether one is set and offers to replace it, never to read it.
     */
    /**
     * The source this key authenticates against, for the "Probar clave" button —
     * null for every setting that is not an API key.
     */
    public function apiKeySource(): ?string
    {
        return match ($this) {
            self::SCOPUS_API_KEY => 'scopus',
            self::WOS_API_KEY => 'wos',
            default => null,
        };
    }

    public function isSecret(): bool
    {
        return match ($this) {
            self::SCOPUS_API_KEY, self::WOS_API_KEY => true,
            default => false,
        };
    }

    /**
     * Whether the engine currently reads this. The Scopus and WoS keys are stored
     * for when those sources are wired in; today the engine is OpenAlex-only, so the
     * form marks them as inactive rather than implying they do something.
     */
    public function isLive(): bool
    {
        return !$this->isSecret();
    }

    public function label(): string
    {
        return match ($this) {
            self::ENGINE_URL => 'URL del motor',
            self::OLLAMA_BASE => 'Servidor de Ollama',
            self::OLLAMA_MODEL => 'Modelo',
            self::MAX_WORKS => 'Máximo de obras por autor',
            self::MAX_CITING_PER_WORK => 'Máximo de citas por obra',
            self::COUNT_TOLERANCE => 'Tolerancia de conteo',
            self::SCOPUS_API_KEY => 'Clave de API de Scopus',
            self::WOS_API_KEY => 'Clave de API de Web of Science',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::ENGINE_URL => 'Dónde escucha el servicio Python. Cambiarlo aquí no reinicia nada.',
            self::OLLAMA_BASE => 'Incluye el puerto, p. ej. http://132.248.196.38:11434.',
            self::OLLAMA_MODEL => 'Debe estar descargado en ese servidor (ollama pull).',
            self::MAX_WORKS => 'Corta la consulta a OpenAlex; los análisis truncados se marcan con un aviso.',
            self::MAX_CITING_PER_WORK => 'Límite de trabajos citantes recuperados por artículo.',
            self::COUNT_TOLERANCE => 'Diferencia aceptada entre las citas recuperadas y las que reporta OpenAlex (0.10 = 10 %).',
            self::SCOPUS_API_KEY => 'Se guarda cifrada. El motor todavía no consulta Scopus.',
            self::WOS_API_KEY => 'Se guarda cifrada. El motor todavía no consulta Web of Science.',
        };
    }

    /** HTML input type for the settings form. */
    public function inputType(): string
    {
        return match ($this) {
            self::MAX_WORKS, self::MAX_CITING_PER_WORK => 'number',
            self::COUNT_TOLERANCE => 'number',
            self::SCOPUS_API_KEY, self::WOS_API_KEY => 'password',
            default => 'text',
        };
    }

    /**
     * Rejects values the engine would choke on. Returns an error message, or null
     * when the value is acceptable.
     */
    public function validate(string $value): ?string
    {
        if ($value === '') {
            return null; // clearing a setting falls back to the env default
        }

        return match ($this) {
            self::ENGINE_URL, self::OLLAMA_BASE => filter_var($value, FILTER_VALIDATE_URL) === false
                ? 'Debe ser una URL completa, incluido http:// y el puerto.'
                : null,
            self::MAX_WORKS, self::MAX_CITING_PER_WORK => (!ctype_digit($value) || (int) $value < 1)
                ? 'Debe ser un número entero mayor que cero.'
                : null,
            self::COUNT_TOLERANCE => (!is_numeric($value) || (float) $value < 0 || (float) $value > 1)
                ? 'Debe ser un número entre 0 y 1 (0.10 = 10 %).'
                : null,
            default => null,
        };
    }
}
