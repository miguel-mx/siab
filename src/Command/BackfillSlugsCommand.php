<?php

namespace App\Command;

use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces the id-based placeholder slugs the slug migration wrote with readable
 * ones. Idempotent: rows that already have a real slug are left alone, so it is
 * safe to re-run after importing data that was inserted outside the app.
 */
#[AsCommand(
    name: 'app:slugs:backfill',
    description: 'Genera slugs legibles para investigadores y análisis que aún no lo tienen.',
)]
final class BackfillSlugsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlugGenerator $slugs,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Researchers first: a run's slug is built on top of its researcher's.
        $researchers = 0;
        foreach ($this->em->getRepository(Researcher::class)->findAll() as $researcher) {
            if (!$this->isPlaceholder($researcher->getSlug(), 'investigador-')) {
                continue;
            }

            $researcher->setSlug($this->slugs->forResearcher($researcher->getDisplayName()));
            ++$researchers;
        }
        $this->em->flush();

        $runs = 0;
        foreach ($this->em->getRepository(AnalysisRun::class)->findAll() as $run) {
            if (!$this->isPlaceholder($run->getSlug(), 'analisis-')) {
                continue;
            }

            $run->setSlug($this->slugs->forRun($run));
            ++$runs;
        }
        $this->em->flush();

        $io->success(sprintf('%d investigadores y %d análisis actualizados.', $researchers, $runs));

        return Command::SUCCESS;
    }

    /** A slug is still the migration's placeholder when it is "<prefix><id>" (or empty). */
    private function isPlaceholder(?string $slug, string $prefix): bool
    {
        return $slug === null
            || $slug === ''
            || preg_match(sprintf('/^%s\d+$/', preg_quote($prefix, '/')), $slug) === 1;
    }
}
