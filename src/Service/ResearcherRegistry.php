<?php

namespace App\Service;

use App\Engine\Dto\AuthorDto;
use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the roster in step with what the engine resolves. Shared by the CLI and
 * the new-analysis form so "resolve → roster entry" behaves identically in both.
 */
final class ResearcherRegistry
{
    public function __construct(
        private readonly ResearcherRepository $researchers,
        private readonly EntityManagerInterface $em,
        private readonly SlugGenerator $slugs,
    ) {
    }

    /**
     * Find the roster entry matching a resolved author, or create it. Ids the user
     * curated are never overwritten — only gaps are filled — and are stored bare,
     * without the URL prefixes the engine emits.
     */
    public function fromAuthor(AuthorDto $author): Researcher
    {
        $openalexId = Researcher::normalizeOpenalexId($author->openalexId);
        $orcid = Researcher::normalizeOrcid($author->orcid);

        $researcher = ($openalexId !== null ? $this->researchers->findOneByOpenalexId($openalexId) : null)
            ?? ($orcid !== null ? $this->researchers->findOneByOrcid($orcid) : null);

        if ($researcher === null) {
            $researcher = (new Researcher())
                ->setDisplayName($author->displayName)
                ->setSlug($this->slugs->forResearcher($author->displayName));
            $this->em->persist($researcher);
        }

        if ($researcher->getOpenalexId() === null) {
            $researcher->setOpenalexId($openalexId);
        }
        if ($researcher->getOrcid() === null) {
            $researcher->setOrcid($orcid);
        }

        $researcher->setWorksCount($author->worksCount)->touch();
        $this->em->flush();

        return $researcher;
    }
}
