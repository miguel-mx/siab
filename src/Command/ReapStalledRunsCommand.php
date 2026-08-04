<?php

namespace App\Command;

use App\Service\StalledRunReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Close analyses whose worker died mid-run.
 *
 * The web pages sweep as they are viewed, which covers a used SIAB; this is for
 * the case nobody looks for a week, and for a cron/systemd timer that wants the
 * roster tidy without depending on someone opening a browser.
 */
#[AsCommand(
    name: 'app:runs:reap',
    description: 'Marca como fallidos los análisis que quedaron en proceso sin worker.',
)]
final class ReapStalledRunsCommand extends Command
{
    public function __construct(private readonly StalledRunReaper $reaper)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $closed = $this->reaper->sweep();

        if ($closed === 0) {
            $io->success('Ningún análisis atascado.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            '%d %s cerrado%s por falta de worker.',
            $closed,
            $closed === 1 ? 'análisis' : 'análisis',
            $closed === 1 ? '' : 's',
        ));

        return Command::SUCCESS;
    }
}
