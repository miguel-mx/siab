<?php

namespace App\Tests\Health;

use App\Enum\ServiceState;
use App\Health\PreflightCheck;
use App\Health\ServiceHealth;
use App\Health\ServiceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Which dependency blocks what, checked immediately before a run is queued.
 * The case that prompted this: Ollama down, the analysis queued anyway, and the
 * failure only visible twenty minutes later with the citations already fetched.
 */
final class PreflightCheckTest extends TestCase
{
    private static function health(ServiceState $engine, ServiceState $openalex, ServiceState $ollama): ServiceHealth
    {
        return new ServiceHealth([
            new ServiceStatus('engine', 'Motor de análisis', '', $engine, message: 'sin conexión'),
            new ServiceStatus('openalex', 'OpenAlex API', '', $openalex, message: 'sin conexión'),
            new ServiceStatus('ollama', 'Ollama', '', $ollama, message: 'sin conexión'),
        ], new \DateTimeImmutable());
    }

    public function testEverythingUpAllowsBoth(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::OK, ServiceState::OK, ServiceState::OK));

        self::assertTrue($check->canRun);
        self::assertTrue($check->canReport);
        self::assertNull($check->blocker);
    }

    public function testADownEngineBlocksTheRun(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::DOWN, ServiceState::OK, ServiceState::OK));

        self::assertFalse($check->canRun);
        self::assertStringContainsString('motor', (string) $check->blocker);
    }

    public function testADownOpenalexBlocksTheRun(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::OK, ServiceState::DOWN, ServiceState::OK));

        self::assertFalse($check->canRun);
        self::assertStringContainsString('OpenAlex', (string) $check->blocker);
    }

    /** The whole point: no Ollama costs the prose, not the citation analysis. */
    public function testADownOllamaDropsOnlyTheReport(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::OK, ServiceState::OK, ServiceState::DOWN));

        self::assertTrue($check->canRun);
        self::assertFalse($check->canReport);
        self::assertNull($check->blocker);
        self::assertStringContainsString('Ollama', (string) $check->reportBlocker);
    }

    /**
     * OpenAlex is relayed by the engine, so it reads UNKNOWN when the engine cannot
     * be asked. That is absence of evidence, not a reason to refuse the run.
     */
    public function testAnUnknownOpenalexDoesNotBlockTheRun(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::OK, ServiceState::UNKNOWN, ServiceState::OK));

        self::assertTrue($check->canRun);
    }

    public function testADegradedEngineStillRuns(): void
    {
        $check = PreflightCheck::from(self::health(ServiceState::DEGRADED, ServiceState::OK, ServiceState::OK));

        self::assertTrue($check->canRun, 'degraded is usable — slow is not down');
    }
}
