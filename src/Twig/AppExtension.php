<?php

namespace App\Twig;

use App\Entity\Article;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Spanish date formatting for the UI. Uses intl directly rather than pulling in
 * twig/intl-extra, and pins es_MX so output does not depend on the request locale.
 */
final class AppExtension extends AbstractExtension
{
    private const LOCALE = 'es_MX';

    public function getFilters(): array
    {
        return [
            new TwigFilter('fecha', $this->fecha(...)),
            new TwigFilter('hace', $this->hace(...)),
            new TwigFilter('doi_url', $this->doiUrl(...)),
        ];
    }

    /**
     * Resolver URL for a DOI, from either shape it may be stored in — the column
     * holds bare DOIs from Article::normalizeDoi() onwards, and resolved URLs for
     * everything written before that. Null when there is nothing to link to, so
     * templates can fall back to plain text instead of an empty href.
     */
    public function doiUrl(?string $doi): ?string
    {
        $bare = Article::normalizeDoi($doi);

        // A DOI that does not start with its registry prefix is not one; linking
        // it would send the reader to a doi.org error page.
        return $bare !== null && str_starts_with($bare, '10.')
            ? 'https://doi.org/' . $bare
            : null;
    }

    /** 2026-07-28 → "28 jul 2026" */
    public function fecha(?\DateTimeInterface $date, string $pattern = 'd MMM y'): string
    {
        if ($date === null) {
            return '—';
        }

        $formatter = new \IntlDateFormatter(
            self::LOCALE,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            $pattern,
        );

        return rtrim((string) $formatter->format($date), '.');
    }

    /** Elapsed time, the way the panel prints it: "hace 30 s", "hace 4 min", "hace 2 h". */
    public function hace(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        $seconds = max(0, time() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => sprintf('hace %d s', $seconds),
            $seconds < 3600 => sprintf('hace %d min', intdiv($seconds, 60)),
            $seconds < 86400 => sprintf('hace %d h', intdiv($seconds, 3600)),
            default => sprintf('hace %d d', intdiv($seconds, 86400)),
        };
    }
}
