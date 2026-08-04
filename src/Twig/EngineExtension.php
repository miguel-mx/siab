<?php

namespace App\Twig;

use App\Engine\EngineLauncher;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether this deployment can start the engine from the web UI.
 *
 * A template flag rather than a controller variable: the "Arrancar motor" button
 * appears next to any engine-down message (the panel, the new-analysis screen, the
 * admin page), and threading the same boolean through three controllers would rot
 * the first time a fourth place needs it. The call touches no I/O — it only reads
 * the configured command.
 */
final class EngineExtension extends AbstractExtension
{
    public function __construct(private readonly EngineLauncher $launcher)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('motor_arrancable', $this->launcher->isConfigured(...)),
        ];
    }
}
