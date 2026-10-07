<?php

namespace App\Engine;

use App\Engine\Dto\AnalysisResultDto;
use App\Engine\Dto\ArticleDto;
use App\Engine\Dto\CitingWorkDto;
use App\Entity\AnalysisRun;
use App\Entity\Article;
use App\Entity\CitingWork;
use App\Entity\Researcher;

/**
 * Populates an AnalysisRun (and its Article / CitingWork children) from an engine
 * result. It trusts the engine's per-article A/B/self *counts* as authoritative,
 * and independently re-derives each citing work's label with the same rule so the
 * UI can filter individual references. The two are consistent by construction.
 */
final class AnalysisResultMapper
{
    /**
     * Fill a fresh run from the engine result. Does not flush; the caller owns the
     * transaction and the run's status/timestamps.
     */
    public function apply(AnalysisRun $run, AnalysisResultDto $dto): void
    {
        $run->setRunTimestamp($dto->runTimestamp);
        $run->setFlags($dto->flags);
        $run->setNotes($dto->notes);
        $run->setRawSnapshot($dto->raw);
        $run->setClassificationRule($dto->classificationRule);
        if ($dto->report !== null) {
            $run->setReport($dto->report);
        }

        // The researcher's own OpenAlex id (URL form, as the engine emits it). The
        // other classifier input is per article: the authors of the cited work.
        $authorId = $dto->author->openalexId;

        $totalA = $totalB = $totalSelf = 0;

        foreach ($dto->articles as $articleDto) {
            $article = $this->mapArticle($articleDto);
            $citedCoauthorIds = array_fill_keys($articleDto->coauthorIds, true);

            foreach ($articleDto->citingWorks as $cwDto) {
                $article->addCitingWork(
                    $this->mapCitingWork($cwDto, $authorId, $citedCoauthorIds)
                );
            }

            $run->addArticle($article);

            $totalA += $articleDto->citesTypeA;
            $totalB += $articleDto->citesTypeB;
            $totalSelf += $articleDto->citesSelf;
        }

        $run->setTotalArticles(count($dto->articles));
        $run->setTotalTypeA($totalA);
        $run->setTotalTypeB($totalB);
        $run->setTotalSelf($totalSelf);
    }

    /**
     * Clips a value to a bounded column's width.
     *
     * The engine merges five sources with no agreement on field lengths — zbMATH
     * puts an entire host citation in `journal`, for instance. A bibliographic
     * record that is slightly clipped is a far better outcome than an analysis that
     * took two minutes and then died on an INSERT, so this never throws.
     */
    private static function fit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private function mapArticle(ArticleDto $d): Article
    {
        return (new Article())
            ->setOpenalexId(Researcher::normalizeOpenalexId($d->openalexId))
            ->setScopusId(self::fit($d->scopusId, 32))
            ->setScopusEid(self::fit($d->scopusEid, 64))
            ->setWosUid(self::fit($d->wosUid, 64))
            ->setZbmathId(self::fit($d->zbmathId, 32))
            ->setInspireRecid(self::fit($d->inspireRecid, 32))
            // Normalised before the cut: the resolver prefix is 16 of the 255 columns.
            ->setDoi(self::fit(Article::normalizeDoi($d->doi), 255))
            ->setTitle($d->title)
            ->setYear($d->year)
            ->setJournal($d->journal)
            ->setAuthors($d->authors)
            ->setWorkType(self::fit($d->workType, 32))
            ->setRepository(self::fit($d->repository, 64))
            ->setOpenalexCitedByCount($d->openalexCitedByCount)
            ->setScopusCitedByCount($d->scopusCitedByCount)
            ->setWosCitedByCount($d->wosCitedByCount)
            ->setZbmathCitedByCount($d->zbmathCitedByCount)
            ->setInspireCitedByCount($d->inspireCitedByCount)
            ->setCitesTypeA($d->citesTypeA)
            ->setCitesTypeB($d->citesTypeB)
            ->setCitesSelf($d->citesSelf);
    }

    /**
     * @param array<string,true> $citedCoauthorIds
     */
    private function mapCitingWork(CitingWorkDto $d, string $authorId, array $citedCoauthorIds): CitingWork
    {
        return (new CitingWork())
            ->setOpenalexId(Researcher::normalizeOpenalexId($d->openalexId))
            // Normalised before the cut: the resolver prefix is 16 of the 255 columns.
            ->setDoi(self::fit(Article::normalizeDoi($d->doi), 255))
            ->setTitle($d->title)
            ->setYear($d->year)
            ->setAuthors($d->authors)
            ->setSource(self::fit($d->source, 32) ?? 'openalex')
            ->setClassification($this->classify($d, $authorId, $citedCoauthorIds));
    }

    /**
     * Deterministic A/B/self, mirroring the engine's classify_citation_type.
     * Precedence: self > B > A.
     *
     * As Rizoma defines it, B means an author *of the cited article* signs the
     * citing work: `$citedCoauthorIds` are that one article's OpenAlex author ids,
     * not everyone the researcher has published with. Citing works or cited
     * articles with no author ids fall through to A.
     *
     * @param array<string,true> $citedCoauthorIds
     */
    private function classify(CitingWorkDto $d, string $authorId, array $citedCoauthorIds): string
    {
        if (in_array($authorId, $d->authorIds, true)) {
            return 'self';
        }
        foreach ($d->authorIds as $id) {
            if (isset($citedCoauthorIds[$id])) {
                return 'B';
            }
        }

        return 'A';
    }
}
