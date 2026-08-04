<?php

namespace App\Doctrine;

use App\Entity\AnalysisRun;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Keeps archived analyses out of every query that touches AnalysisRun.
 *
 * A filter rather than a condition repeated in each repository method, because
 * the thing being protected is the evaluation figures: the Type A share, the
 * monthly counts, the roster's per-researcher totals, a researcher's "current"
 * numbers. Thirteen places would have to remember, including a join in
 * ResearcherRepository that never mentions runs by name — and the fourteenth,
 * written next year, would silently start counting archived rows again.
 *
 * Disabled deliberately in two places, both of which want to see them:
 * AnalysisRunRepository::findBySlugIncludingArchived() (an archived run stays
 * readable by URL) and the history screen's "archivados" filter.
 */
final class ArchivedRunFilter extends SQLFilter
{
    public const NAME = 'archived_runs';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== AnalysisRun::class) {
            return '';
        }

        return sprintf('%s.discarded_at IS NULL', $targetTableAlias);
    }
}
