<?php

namespace App\Service;

use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Readable, stable, unique slugs for the entities that appear in URLs.
 *
 * Accents matter here: the roster is full of names like "Hrušák" and
 * "García-Ferreira", and the slugger transliterates them ("hrusak",
 * "garcia-ferreira") instead of dropping the characters.
 */
final class SlugGenerator
{
    private const MAX_LENGTH = 120;

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Slug for a roster entry: "michael-hrusak", "michael-hrusak-2" on collision. */
    public function forResearcher(string $displayName): string
    {
        $base = $this->base($displayName, 'investigador');

        return $this->unique(Researcher::class, $base);
    }

    /**
     * Slug for one run: the researcher plus when it was launched, so a URL says who
     * and when ("michael-hrusak-20260729-2354"). Runs of the same researcher in the
     * same minute get a counter.
     */
    public function forRun(AnalysisRun $run): string
    {
        $researcher = $run->getResearcher();
        $stamp = ($run->getCreatedAt() ?? new \DateTimeImmutable())->format('Ymd-Hi');
        $base = sprintf('%s-%s', $researcher->getSlug() ?: $this->base($researcher->getDisplayName(), 'analisis'), $stamp);

        return $this->unique(AnalysisRun::class, $base);
    }

    private function base(string $value, string $fallback): string
    {
        $slug = strtolower((string) $this->slugger->slug($value)->truncate(self::MAX_LENGTH, '', false));

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Append -2, -3 … until the slug is free. Slugs are unique per entity, so the
     * check is a cheap indexed lookup.
     *
     * @param class-string $entityClass
     */
    private function unique(string $entityClass, string $base): string
    {
        $repository = $this->em->getRepository($entityClass);
        $slug = $base;
        $suffix = 1;

        while ($repository->findOneBy(['slug' => $slug]) !== null) {
            $slug = sprintf('%s-%d', $base, ++$suffix);
        }

        return $slug;
    }
}
