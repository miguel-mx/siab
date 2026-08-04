<?php

namespace App\Tests\Engine;

use App\Engine\EngineHealthProbe;
use App\Engine\EngineLauncher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The launcher is exercised against real processes in a temporary project dir —
 * the interesting behaviour (does the spawned thing survive PHP, do we notice
 * when it dies) is not visible with a mocked Process.
 */
final class EngineLauncherTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/siab-launcher-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var/log', 0o777, true);
    }

    protected function tearDown(): void
    {
        $pidFile = $this->projectDir.'/var/engine.pid';

        if (is_file($pidFile) && ($pid = (int) file_get_contents($pidFile)) > 0) {
            @posix_kill($pid, \SIGTERM);
        }

        exec('rm -rf '.escapeshellarg($this->projectDir));
    }

    private function launcher(
        string $command,
        EngineHealthProbe $probe,
        string $dir = '.',
        ?string $logPath = null,
        float $handoffGrace = 1.0,
    ): EngineLauncher {
        return new EngineLauncher(
            $probe,
            new NullLogger(),
            $command,
            $dir,
            $this->projectDir,
            $this->projectDir.'/var/log',
            $logPath,
            // Shorter than the deployed default: these tests wait out the grace,
            // and what matters is the decision, not how long it takes.
            $handoffGrace,
        );
    }

    /** @param list<bool> $answers Successive replies from /health */
    private function probe(array $answers): EngineHealthProbe
    {
        return new class($answers) implements EngineHealthProbe {
            /** @param list<bool> $answers */
            public function __construct(private array $answers)
            {
            }

            public function isHealthy(): bool
            {
                return array_shift($this->answers) ?? false;
            }
        };
    }

    public function testWithoutACommandTheFeatureIsSimplyUnavailable(): void
    {
        $launcher = $this->launcher('', $this->probe([]));

        self::assertFalse($launcher->isConfigured());
        self::assertFalse($launcher->start()->ok);
    }

    public function testAnEngineThatIsAlreadyUpIsNotStartedTwice(): void
    {
        $outcome = $this->launcher('true', $this->probe([true]))->start();

        self::assertTrue($outcome->ok);
        self::assertStringContainsString('ya estaba en marcha', $outcome->message);
        self::assertFileDoesNotExist($this->projectDir.'/var/engine.pid');
    }

    public function testASuccessfulStartWaitsForHealthAndLeavesTheProcessRunning(): void
    {
        // Down, still down while booting, then answering.
        $launcher = $this->launcher('sleep 30', $this->probe([false, false, true]));
        $outcome = $launcher->start('admin@example.org');

        self::assertTrue($outcome->ok, $outcome->message);

        $pid = (int) file_get_contents($this->projectDir.'/var/engine.pid');
        self::assertGreaterThan(0, $pid);
        // The pid file holds the engine itself, not a shell that already exited.
        self::assertTrue(posix_kill($pid, 0), 'spawned process should outlive the PHP call');
    }

    public function testACrashOnBootReportsTheEnginesOwnOutput(): void
    {
        $launcher = $this->launcher(
            'sh -c "echo ModuleNotFoundError: no module named app.main >&2; exit 1"',
            $this->probe([false]),
        );

        $started = microtime(true);
        $outcome = $launcher->start();

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('ModuleNotFoundError', $outcome->logTail);
        // Gave up as soon as the process was gone rather than waiting out the timeout.
        self::assertLessThan(15, microtime(true) - $started);
    }

    /**
     * The production shape: the command is `systemctl start …`, which returns as
     * soon as systemd has taken over, well before the engine answers. That exit
     * must not be read as a crash.
     */
    public function testADelegatedStartIsNotMistakenForACrash(): void
    {
        // The command is gone after the first poll; the engine answers on the fourth.
        $launcher = $this->launcher('true', $this->probe([false, false, false, true]), '/nonexistent', handoffGrace: 3.0);

        self::assertTrue($launcher->isConfigured(), 'an unreadable engine dir must not hide the button');
        self::assertFalse($launcher->ownsTheProcess());

        $outcome = $launcher->start();

        self::assertTrue($outcome->ok, $outcome->message);
    }

    public function testTheRequestingUserIsRecordedInTheEngineLog(): void
    {
        $this->launcher('true', $this->probe([false, true]))->start('admin@example.org');

        self::assertStringContainsString(
            'admin@example.org',
            (string) file_get_contents($this->projectDir.'/var/log/engine.log'),
        );
    }

    /** Under systemd the engine writes elsewhere; the admin screen follows it there. */
    public function testTheLogCanLiveOutsideTheProject(): void
    {
        $elsewhere = $this->projectDir.'/elsewhere.log';
        $launcher = $this->launcher(
            'sh -c "echo Address already in use >&2; exit 1"',
            $this->probe([false]),
            '.',
            $elsewhere,
        );

        self::assertSame($elsewhere, $launcher->logFile());
        self::assertStringContainsString('Address already in use', $launcher->start()->logTail);
        self::assertFileDoesNotExist($this->projectDir.'/var/log/engine.log');
    }
}
