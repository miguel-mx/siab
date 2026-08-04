<?php

namespace App\Engine\Dto;

/**
 * The full result of /analyze: the resolved author, the engine's run_timestamp,
 * every analyzed article, and what the engine wants to say about the run.
 *
 * Flags and notes are kept apart because only one of them means anything is wrong:
 * a flag is something a person has to act on and is what makes a run "Por revisar",
 * while a note is provenance ("obras por fuente…", "Scopus: sin clave configurada")
 * that fires on ordinary healthy runs. `raw` retains the exact decoded `result`
 * payload so it can be stored verbatim for audit (AnalysisRun.rawSnapshot).
 */
final readonly class AnalysisResultDto
{
    /**
     * @param ArticleDto[] $articles
     * @param string[]     $flags  Findings that need a human
     * @param string[]     $notes  Context with nothing to act on
     * @param array        $raw    The undecoded-into-DTO `result` array, for the snapshot.
     */
    public function __construct(
        public AuthorDto $author,
        public string $runTimestamp,
        public array $articles,
        public array $flags,
        public array $notes,
        public ?string $report,
        public array $raw,
    ) {
    }

    /**
     * @param array $envelope The full /analyze response: { result: {...}, report: ?string }
     */
    public static function fromResponse(array $envelope): self
    {
        $result = $envelope['result'] ?? [];

        return new self(
            author: AuthorDto::fromArray($result['author']),
            runTimestamp: (string) ($result['run_timestamp'] ?? ''),
            articles: array_map(ArticleDto::fromArray(...), $result['articles'] ?? []),
            flags: array_values(array_map('strval', $result['flags'] ?? [])),
            notes: array_values(array_map('strval', $result['notes'] ?? [])),
            report: $envelope['report'] ?? null,
            raw: $result,
        );
    }

    public function hasFlags(): bool
    {
        return $this->flags !== [];
    }
}
