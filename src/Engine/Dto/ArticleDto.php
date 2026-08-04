<?php

namespace App\Engine\Dto;

/**
 * One of the researcher's works with its per-source counts, the engine's A/B/self
 * totals, and the (deduplicated) list of citing works.
 */
final readonly class ArticleDto
{
    /**
     * @param CitingWorkDto[] $citingWorks
     * @param string[]        $coauthorIds
     */
    public function __construct(
        public ?string $openalexId,
        // Per-source record identifiers. Worth keeping: they are what a reviewer
        // follows to check a figure against Scopus or zbMATH directly, and they
        // spare a re-run from resolving the same record again.
        public ?string $scopusId,
        public ?string $scopusEid,
        public ?string $wosUid,
        public ?string $zbmathId,
        public ?string $inspireRecid,
        public ?string $doi,
        public string $title,
        public ?int $year,
        public ?string $journal,
        public ?string $authors,
        public int $openalexCitedByCount,
        public ?int $scopusCitedByCount,
        public ?int $wosCitedByCount,
        public ?int $zbmathCitedByCount,
        public ?int $inspireCitedByCount,
        public int $citesTypeA,
        public int $citesTypeB,
        public int $citesSelf,
        public array $citingWorks,
        public array $coauthorIds,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(
            openalexId: $d['openalex_id'] ?? null,
            scopusId: $d['scopus_id'] ?? null,
            scopusEid: $d['scopus_eid'] ?? null,
            wosUid: $d['wos_uid'] ?? null,
            zbmathId: $d['zbmath_id'] ?? null,
            inspireRecid: $d['inspire_recid'] ?? null,
            doi: $d['doi'] ?? null,
            title: (string) ($d['title'] ?? '(sin título)'),
            year: isset($d['year']) ? (int) $d['year'] : null,
            journal: $d['journal'] ?? null,
            authors: $d['authors'] ?? null,
            openalexCitedByCount: (int) ($d['openalex_cited_by_count'] ?? 0),
            scopusCitedByCount: isset($d['scopus_cited_by_count']) ? (int) $d['scopus_cited_by_count'] : null,
            wosCitedByCount: isset($d['wos_cited_by_count']) ? (int) $d['wos_cited_by_count'] : null,
            zbmathCitedByCount: isset($d['zbmath_cited_by_count']) ? (int) $d['zbmath_cited_by_count'] : null,
            inspireCitedByCount: isset($d['inspire_cited_by_count']) ? (int) $d['inspire_cited_by_count'] : null,
            citesTypeA: (int) ($d['cites_type_a'] ?? 0),
            citesTypeB: (int) ($d['cites_type_b'] ?? 0),
            citesSelf: (int) ($d['cites_self'] ?? 0),
            citingWorks: array_map(CitingWorkDto::fromArray(...), $d['citing_works'] ?? []),
            coauthorIds: array_values(array_map('strval', $d['coauthor_ids'] ?? [])),
        );
    }
}
