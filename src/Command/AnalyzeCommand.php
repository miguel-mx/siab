<?php

namespace App\Command;

use App\Engine\CitationEngineClient;
use App\Engine\CitationEngineException;
use App\Entity\Researcher;
use App\Service\AnalysisRunner;
use App\Service\ResearcherRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI front door to the pipeline, until the web UI exists: resolves a researcher
 * through the engine, adds them to the roster if new, then runs the analysis
 * inline (default) or hands it to the async transport with --queue.
 */
#[AsCommand(
    name: 'app:analyze',
    description: 'Analiza las citas de un investigador (ORCID, OpenAlex ID o nombre).',
)]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly CitationEngineClient $engine,
        private readonly ResearcherRegistry $registry,
        private readonly AnalysisRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'ORCID, OpenAlex ID o nombre del investigador')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Encolar para un worker en lugar de ejecutar aquí')
            ->addOption('report', null, InputOption::VALUE_NONE, 'Pedir también el informe narrativo (requiere Ollama)')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Idioma del informe (es|en)', 'es');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = (string) $input->getArgument('query');

        if (!$this->engine->isHealthy()) {
            $io->error('El motor de análisis no responde. Arráncalo en el puerto 8001.');

            return Command::FAILURE;
        }

        try {
            $resolved = $this->engine->resolve($query);
        } catch (CitationEngineException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($resolved->needsDisambiguation()) {
            $io->warning(sprintf('"%s" es ambiguo. Vuelve a ejecutar con un ORCID u OpenAlex ID:', $query));
            $io->table(
                ['Nombre', 'OpenAlex', 'ORCID', 'Obras'],
                array_map(static fn ($c) => [
                    $c->displayName,
                    Researcher::normalizeOpenalexId($c->openalexId),
                    Researcher::normalizeOrcid($c->orcid) ?? '—',
                    $c->worksCount,
                ], $resolved->candidates)
            );

            return Command::INVALID;
        }

        $researcher = $this->registry->fromAuthor($resolved->author);
        $io->section(sprintf(
            '%s (OpenAlex %s, %d obras)',
            $researcher->getDisplayName(),
            $researcher->getOpenalexId() ?? '—',
            $researcher->getWorksCount() ?? 0
        ));

        $wantReport = (bool) $input->getOption('report');
        $language = (string) $input->getOption('language');

        if ($input->getOption('queue')) {
            $run = $this->runner->queue($researcher, wantReport: $wantReport, reportLanguage: $language);
            $io->success(sprintf(
                'Análisis #%d en cola. Procesa con: php bin/console messenger:consume async -vv',
                $run->getId()
            ));

            return Command::SUCCESS;
        }

        $io->text('Ejecutando el análisis (puede tardar varios minutos)…');
        $run = $this->runner->create($researcher, reportLanguage: $language);
        $this->runner->execute($run, $wantReport);

        if ($run->getStatus()->isTerminal() && $run->getErrorMessage() !== null) {
            $io->error(sprintf('Análisis #%d: %s', $run->getId(), $run->getErrorMessage()));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Análisis' => (string) $run->getId()],
            ['Estado' => $run->getStatus()->label()],
            ['Artículos' => (string) $run->getTotalArticles()],
            ['Citas totales' => (string) $run->getTotalCitations()],
            ['Tipo A (externas)' => (string) $run->getTotalTypeA()],
            ['Tipo B (coautores)' => (string) $run->getTotalTypeB()],
            ['Autocitas' => (string) $run->getTotalSelf()],
            ['Marcas' => $run->getFlags() === [] ? 'ninguna' : implode('; ', $run->getFlags())],
        );

        $io->success(sprintf('Análisis #%d guardado.', $run->getId()));

        return Command::SUCCESS;
    }

}
