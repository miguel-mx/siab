<?php

namespace App\Engine\Dto;

/**
 * Outcome of /resolve. `kind === 'author'` means a unique hit (ORCID / OpenAlex ID);
 * `kind === 'candidates'` means a name search that needs human disambiguation.
 */
final readonly class ResolveResult
{
    public const KIND_AUTHOR = 'author';
    public const KIND_CANDIDATES = 'candidates';

    /**
     * @param AuthorDto[] $candidates
     */
    public function __construct(
        public string $kind,
        public ?AuthorDto $author,
        public array $candidates,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(
            kind: (string) ($d['kind'] ?? self::KIND_CANDIDATES),
            author: isset($d['author']) && $d['author'] !== null ? AuthorDto::fromArray($d['author']) : null,
            candidates: array_map(AuthorDto::fromArray(...), $d['candidates'] ?? []),
        );
    }

    public function isUnique(): bool
    {
        return $this->kind === self::KIND_AUTHOR && $this->author !== null;
    }

    public function needsDisambiguation(): bool
    {
        return $this->kind === self::KIND_CANDIDATES;
    }
}
