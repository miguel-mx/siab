<?php

namespace App\Tests\Engine;

use App\Engine\Dto\AnalysisResultDto;
use PHPUnit\Framework\TestCase;

/**
 * Only findings a person must act on may mark a run "Por revisar".
 *
 * Before the split, the engine's provenance line ("Obras por fuente — …") was a
 * flag, so every multi-source run came back needing review and the status stopped
 * distinguishing anything: 8 of 12 finished runs carried it.
 */
final class FlagsAndNotesTest extends TestCase
{
    /**
     * @param list<string> $flags
     * @param list<string> $notes
     */
    private function dto(array $flags, array $notes): AnalysisResultDto
    {
        return AnalysisResultDto::fromResponse([
            'result' => [
                'author' => ['display_name' => 'Daniel Juan-Pineda', 'openalex_id' => 'A5031242743'],
                'run_timestamp' => '20260803T200000Z',
                'articles' => [],
                'flags' => $flags,
                'notes' => $notes,
            ],
        ]);
    }

    public function testNotesAloneDoNotMarkARunForReview(): void
    {
        $dto = $this->dto([], [
            'Obras por fuente — OpenAlex 8, Scopus 0, WoS 0, zbMATH 33, INSPIRE 0; 34 tras fusionar por DOI.',
            'Scopus: sin clave de API configurada.',
        ]);

        self::assertFalse($dto->hasFlags(), 'provenance is not a reason to review a run');
        self::assertCount(2, $dto->notes);
    }

    public function testAFindingThatNeedsActionStillMarksTheRun(): void
    {
        $dto = $this->dto(
            ['zbMATH: código de autor deducido del nombre (juan-pineda.daniel); confírmalo en la ficha.'],
            ['Scopus: sin clave de API configurada.'],
        );

        self::assertTrue($dto->hasFlags());
    }

    /** Runs analysed before the split have no notes key at all. */
    public function testAResponseWithoutNotesIsNotAnError(): void
    {
        $dto = AnalysisResultDto::fromResponse([
            'result' => [
                'author' => ['display_name' => 'X', 'openalex_id' => 'A1'],
                'run_timestamp' => '',
                'articles' => [],
                'flags' => ['algo que revisar'],
            ],
        ]);

        self::assertSame([], $dto->notes);
        self::assertTrue($dto->hasFlags());
    }
}
