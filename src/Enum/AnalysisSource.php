<?php

namespace App\Enum;

/**
 * A bibliographic source one run may draw on.
 *
 * OpenAlex is not optional and never appears as a choice: it is the only source
 * that exposes author identifiers, so it is what makes a citation classifiable as
 * Type B or a self-citation at all. Drop it and every citation would fall back to
 * Type A by default — an analysis in name only.
 *
 * The rest are enrichment, and each is skipped differently by the engine: zbMATH
 * and INSPIRE have explicit switches, while Scopus and Web of Science are simply
 * not queried when no API key is sent.
 */
enum AnalysisSource: string
{
    case OPENALEX = 'openalex';
    case SCOPUS = 'scopus';
    case WOS = 'wos';
    case ZBMATH = 'zbmath';
    case INSPIRE = 'inspire';

    public function label(): string
    {
        return match ($this) {
            self::OPENALEX => 'OpenAlex',
            self::SCOPUS => 'Scopus',
            self::WOS => 'Web of Science',
            self::ZBMATH => 'zbMATH',
            self::INSPIRE => 'INSPIRE-HEP',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::OPENALEX => 'Imprescindible: es la única fuente con identificadores de autor, y sin ellos no se distingue una cita Tipo B de una Tipo A.',
            self::SCOPUS => 'Buena cobertura, especialmente de revistas indexadas. Requiere clave de API.',
            self::WOS => 'La suscripción actual no devuelve conteos de citas; sólo sirve para contrastar cobertura. Requiere clave de API.',
            self::ZBMATH => 'Cobertura matemática que OpenAlex no siempre tiene. Sin clave: es abierta.',
            self::INSPIRE => 'Sólo física de altas energías. La mayoría de los matemáticos no tienen perfil.',
        };
    }

    /** OpenAlex is always used, so it is never offered as a choice. */
    public function isOptional(): bool
    {
        return $this !== self::OPENALEX;
    }

    /** Sources the engine only queries when SIAB sends a key for them. */
    public function apiKeySetting(): ?SettingKey
    {
        return match ($this) {
            self::SCOPUS => SettingKey::SCOPUS_API_KEY,
            self::WOS => SettingKey::WOS_API_KEY,
            default => null,
        };
    }

    /**
     * A run's stored `sources` column back as cases, ignoring anything that is no
     * longer a source we know about.
     *
     * @return list<self>
     */
    public static function parseList(string $stored): array
    {
        $sources = [];

        foreach (explode(',', $stored) as $value) {
            $source = self::tryFrom(trim($value));

            if ($source !== null) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s) => $s->isOptional()));
    }
}
