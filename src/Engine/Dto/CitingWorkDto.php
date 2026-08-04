<?php

namespace App\Engine\Dto;

/**
 * A work citing one of the researcher's articles. `authorIds` (OpenAlex URL form)
 * is what the classifier compares against; it is not persisted, only used to
 * derive the A/B/self label.
 */
final readonly class CitingWorkDto
{
    /**
     * @param string[] $authors
     * @param string[] $authorIds
     */
    public function __construct(
        public ?string $openalexId,
        public ?string $doi,
        public string $title,
        public ?int $year,
        public array $authors,
        public array $authorIds,
        public string $source,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(
            openalexId: $d['openalex_id'] ?? null,
            doi: $d['doi'] ?? null,
            title: (string) ($d['title'] ?? '(sin título)'),
            year: isset($d['year']) ? (int) $d['year'] : null,
            authors: array_values(array_map('strval', $d['authors'] ?? [])),
            authorIds: array_values(array_map('strval', $d['author_ids'] ?? [])),
            source: (string) ($d['source'] ?? 'openalex'),
        );
    }
}
