<?php

namespace App\Health;

use App\Enum\ServiceState;

/**
 * What the services allow right now, asked immediately before an analysis is
 * queued rather than when the form was drawn.
 *
 * The distinction that matters is which dependency each part of the run needs.
 * OpenAlex (through the engine) produces every figure, so without it there is
 * nothing to queue. Ollama only writes the prose: when it is down the analysis is
 * still perfectly good, so the run goes ahead without a report instead of failing
 * twenty minutes later with the citations already fetched — the report can be
 * asked for from the run's page once Ollama is back.
 */
final readonly class PreflightCheck
{
    private function __construct(
        public bool $canRun,
        public bool $canReport,
        /** Why the run cannot start; null when it can. */
        public ?string $blocker = null,
        /** Why the report was dropped; null when it was not. */
        public ?string $reportBlocker = null,
    ) {
    }

    public static function from(ServiceHealth $health): self
    {
        $engine = $health->get('engine');

        if ($engine === null || !$engine->state->isUsable()) {
            return new self(false, false, blocker: sprintf(
                'El motor de análisis no responde (%s). Arráncalo antes de lanzar el análisis.',
                $engine?->message ?? 'sin conexión',
            ));
        }

        $openalex = $health->get('openalex');

        if ($openalex !== null && $openalex->state === ServiceState::DOWN) {
            return new self(false, false, blocker: sprintf(
                'OpenAlex no responde (%s). Todas las cifras salen de ahí, así que no tiene sentido lanzar el análisis ahora.',
                $openalex->message ?? 'sin conexión',
            ));
        }

        $ollama = $health->get('ollama');

        if ($ollama !== null && !$ollama->state->isUsable()) {
            return new self(true, false, reportBlocker: sprintf(
                'Ollama no responde (%s), así que el análisis se lanzó sin informe. Puedes pedirlo desde la página del análisis cuando vuelva.',
                $ollama->message ?? 'sin conexión',
            ));
        }

        return new self(true, true);
    }
}
