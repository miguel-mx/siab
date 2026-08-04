<?php

namespace App\Engine\Dto;

/**
 * A resolved OpenAlex author. IDs are kept in the engine's URL form
 * (e.g. https://openalex.org/A…) — normalize when persisting to a Researcher.
 */
final readonly class AuthorDto
{
    public function __construct(
        public string $openalexId,
        public string $displayName,
        public ?string $orcid,
        public int $worksCount,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(
            openalexId: (string) $d['openalex_id'],
            displayName: (string) $d['display_name'],
            orcid: $d['orcid'] ?? null,
            worksCount: (int) ($d['works_count'] ?? 0),
        );
    }
}
