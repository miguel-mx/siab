<?php

namespace App\Tests\Engine;

use App\Engine\AnalysisResultMapper;
use App\Engine\Dto\AnalysisResultDto;
use App\Entity\AnalysisRun;
use PHPUnit\Framework\TestCase;

/**
 * Offline verification of the DTO parsing + entity mapping using the real
 * /analyze response for Daniel Juan-Pineda (tests/Fixtures/analyze_pineda.json).
 * Proves the independently re-derived per-citing-work A/B/self labels reconcile
 * with the engine's per-article counts.
 *
 * The fixture's cites_type_a / cites_type_b were recomputed when Type B became a
 * per-article rule (an author of the cited work, as Rizoma defines it, instead of
 * any co-author of the researcher): 33 citations by co-authors of *other* papers
 * moved from B to A, so the run reads 146/11/68 instead of 113/44/68. Citing
 * works, author ids and self-citations are untouched.
 */
final class AnalysisResultMapperTest extends TestCase
{
    private function loadDto(): AnalysisResultDto
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/analyze_pineda.json');
        self::assertNotFalse($json, 'fixture missing');

        return AnalysisResultDto::fromResponse(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTotalsMatchTheKnownRun(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        self::assertSame(41, $run->getTotalArticles());
        self::assertSame(146, $run->getTotalTypeA());
        self::assertSame(11, $run->getTotalTypeB());
        self::assertSame(68, $run->getTotalSelf());
        self::assertSame([], $run->getFlags());
        self::assertNotNull($run->getRawSnapshot());
        self::assertNull($run->getReport()); // want_report was false
    }

    public function testPerWorkLabelsReconcileWithEngineCounts(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        $labelA = $labelB = $labelSelf = 0;
        foreach ($run->getArticles() as $article) {
            $a = $b = $s = 0;
            foreach ($article->getCitingWorks() as $cw) {
                match ($cw->getClassification()) {
                    'A' => $a++,
                    'B' => $b++,
                    'self' => $s++,
                };
            }
            // Every article's re-derived labels must equal the engine's counts.
            self::assertSame($article->getCitesTypeA(), $a, 'Type A mismatch on: ' . $article->getTitle());
            self::assertSame($article->getCitesTypeB(), $b, 'Type B mismatch on: ' . $article->getTitle());
            self::assertSame($article->getCitesSelf(), $s, 'self mismatch on: ' . $article->getTitle());
            $labelA += $a; $labelB += $b; $labelSelf += $s;
        }

        self::assertSame(146, $labelA);
        self::assertSame(11, $labelB);
        self::assertSame(68, $labelSelf);
    }

    public function testOpenAlexIdsAreNormalizedToBareForm(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, $this->loadDto());

        $first = $run->getArticles()->first();
        self::assertMatchesRegularExpression('/^W\d+$/', $first->getOpenalexId());
    }

    /**
     * The fixture predates the stamp, so its run reads as classified under the
     * earlier rule; a result that carries it is recorded as current.
     */
    public function testRecordsTheClassificationRule(): void
    {
        $old = new AnalysisRun();
        (new AnalysisResultMapper())->apply($old, $this->loadDto());

        self::assertNull($old->getClassificationRule());
        self::assertFalse($old->isClassifiedUnderCurrentRule());

        $new = new AnalysisRun();
        (new AnalysisResultMapper())->apply($new, AnalysisResultDto::fromResponse([
            'result' => [
                'author' => ['openalex_id' => 'https://openalex.org/A1', 'display_name' => 'X', 'works_count' => 0],
                'run_timestamp' => '20261007T000000Z',
                'classification_rule' => AnalysisRun::CLASSIFICATION_RULE,
            ],
        ]));

        self::assertTrue($new->isClassifiedUnderCurrentRule());
    }

    /**
     * Type B is decided against the authors of the cited article only. X (A2)
     * co-wrote the first article but not the second, so X citing the second
     * without the researcher is Type A there — even though X is a co-author.
     */
    public function testCoauthorOfAnotherArticleIsTypeA(): void
    {
        $citing = ['title' => 'Por X', 'author_ids' => ['https://openalex.org/A2', 'https://openalex.org/A9']];

        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, AnalysisResultDto::fromResponse([
            'result' => [
                'author' => ['openalex_id' => 'https://openalex.org/A1', 'display_name' => 'X', 'works_count' => 2],
                'run_timestamp' => '20261007T000000Z',
                'articles' => [
                    ['title' => 'Con X', 'coauthor_ids' => ['https://openalex.org/A2'], 'citing_works' => [$citing]],
                    ['title' => 'Con Y', 'coauthor_ids' => ['https://openalex.org/A3'], 'citing_works' => [$citing]],
                ],
            ],
        ]));

        [$withX, $withY] = $run->getArticles()->toArray();

        self::assertSame('B', $withX->getCitingWorks()->first()->getClassification());
        self::assertSame('A', $withY->getCitingWorks()->first()->getClassification());
    }

    /**
     * The engine's work type and repository reach the entity, and drive isPreprint().
     *
     * Built by hand rather than from the fixture: that run predates the field, so
     * every article in it carries no type — which is itself the case the last
     * assertion pins down. An unreported type must never read as "not a preprint"
     * in the sense of being *known* not to be one, but it must not be hidden either.
     */
    public function testCarriesTheWorkTypeAndRepository(): void
    {
        $run = new AnalysisRun();
        (new AnalysisResultMapper())->apply($run, AnalysisResultDto::fromResponse([
            'result' => [
                'author' => ['openalex_id' => 'https://openalex.org/A1', 'display_name' => 'X', 'works_count' => 2],
                'run_timestamp' => '20260904T000000Z',
                'articles' => [
                    [
                        'title' => 'El preprint',
                        'work_type' => 'preprint',
                        'repository' => 'arXiv',
                        'doi' => '10.48550/arXiv.2301.01234',
                    ],
                    ['title' => 'El artículo', 'work_type' => 'article'],
                    ['title' => 'Sólo en zbMATH'],
                ],
            ],
        ]));

        [$preprint, $article, $untyped] = $run->getArticles()->toArray();

        self::assertTrue($preprint->isPreprint());
        self::assertSame('arXiv', $preprint->getRepository());

        self::assertFalse($article->isPreprint());
        self::assertNull($article->getRepository());

        // No source but OpenAlex reports a type; unclassified is not "preprint".
        self::assertNull($untyped->getWorkType());
        self::assertFalse($untyped->isPreprint());
    }

}
