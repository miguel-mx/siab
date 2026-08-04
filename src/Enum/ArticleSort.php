<?php

namespace App\Enum;

use Doctrine\ORM\QueryBuilder;

/**
 * How a run's articles are ordered on screen.
 *
 * The default is the most-cited first, because that is the order in which the
 * articles account for the total: the top few carry most of the citations, so
 * anyone checking a figure starts there. The others are for reading the output as
 * a publication record rather than as a count.
 */
enum ArticleSort: string
{
    case CITED = 'citas';
    case NEWEST = 'recientes';
    case OLDEST = 'antiguos';
    case TITLE = 'titulo';

    public function label(): string
    {
        return match ($this) {
            self::CITED => 'Más citados',
            self::NEWEST => 'Más recientes',
            self::OLDEST => 'Más antiguos',
            self::TITLE => 'Título (A–Z)',
        };
    }

    public static function from_(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::CITED;
    }

    /**
     * Apply this ordering to a query over articles aliased `a`.
     *
     * Every option ends with the id, and that is not cosmetic: articles that tie —
     * same citation count, same year, and there are many — would otherwise come back
     * in whatever order the database felt like, and paging would repeat some rows
     * while skipping others.
     *
     * Unknown years are pushed to the end in both directions rather than sorting as
     * zero, which would put every undated article at the top of "más antiguos".
     */
    public function applyTo(QueryBuilder $qb): QueryBuilder
    {
        // DQL will not take a function in ORDER BY, so the coalesced year is selected
        // as a HIDDEN alias and ordered by that. HIDDEN keeps it out of the result.
        $ordered = match ($this) {
            self::CITED => $qb
                ->addSelect('COALESCE(a.year, 0) AS HIDDEN anio')
                ->orderBy('a.citesTypeA + a.citesTypeB + a.citesSelf', 'DESC')
                ->addOrderBy('anio', 'DESC'),
            self::NEWEST => $qb
                ->addSelect('COALESCE(a.year, 0) AS HIDDEN anio')
                ->orderBy('anio', 'DESC'),
            self::OLDEST => $qb
                ->addSelect('COALESCE(a.year, 9999) AS HIDDEN anio')
                ->orderBy('anio', 'ASC'),
            self::TITLE => $qb->orderBy('a.title', 'ASC'),
        };

        return $ordered->addOrderBy('a.id', 'ASC');
    }
}
