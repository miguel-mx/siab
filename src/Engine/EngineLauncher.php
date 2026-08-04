<?php

namespace App\Engine;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts the Python engine (../citas-engine) from the administration screen.
 *
 * Nobody who uses SIAB has a shell on this machine, so "arráncalo en el puerto
 * 8001" was never an instruction they could follow. What the button runs is fixed
 * by the deployment (ENGINE_START_COMMAND) and never comes from the request: an
 * administrator can start the service, not choose a command to run on the server.
 *
 * The process is detached with setsid so it outlives the PHP worker that spawned
 * it, everything it prints goes to var/log/engine.log, and we wait for /health to
 * answer before reporting success — a uvicorn that dies on an import error would
 * otherwise look exactly like a start that worked.
 */
final class EngineLauncher
{
    /** Seconds to wait for /health after spawning. Cold imports take a few. */
    private const READY_TIMEOUT = 25;
    private const POLL_INTERVAL_US = 500_000;
    /** Bytes of engine.log read back when a start fails. */
    private const TAIL_BYTES = 8192;
    private const TAIL_LINES = 15;

    public function __construct(
        private readonly EngineHealthProbe $engine,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::ENGINE_START_COMMAND)%')]
        private readonly ?string $startCommand,
        #[Autowire('%env(default::ENGINE_DIR)%')]
        private readonly ?string $engineDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
        // Systemd writes the engine's output where its own user can; pointing SIAB
        // at that file keeps a failed start diagnosable from the admin screen
        // instead of only through journalctl.
        #[Autowire('%env(default::ENGINE_LOG)%')]
        private readonly ?string $logPath = null,
        /** Seconds still given to /health after the launched command exits. */
        private readonly float $handoffGrace = 8.0,
    ) {
    }

    /**
     * Whether this deployment knows how to start the engine at all.
     *
     * Deliberately not a check on the engine directory: under Apache the command is
     * `sudo -n systemctl start citas-engine.service`, and the web user has no
     * business being able to read the engine's code — requiring the directory would
     * hide the button in exactly the deployment it matters most in.
     */
    public function isConfigured(): bool
    {
        return $this->command() !== '';
    }

    /** True when we spawn the engine ourselves rather than asking systemd to. */
    public function ownsTheProcess(): bool
    {
        return is_dir($this->workingDirectory());
    }

    /** The command as configured — shown to administrators, never taken from input. */
    public function command(): string
    {
        return trim((string) $this->startCommand);
    }

    public function workingDirectory(): string
    {
        $dir = trim((string) $this->engineDir) ?: '../citas-engine';
        $path = str_starts_with($dir, '/') ? $dir : $this->projectDir.'/'.$dir;

        // Resolved when it exists, so the admin screen shows /home/…/citas-engine
        // rather than /home/…/siab/../citas-engine.
        return realpath($path) ?: $path;
    }

    public function logFile(): string
    {
        $path = trim((string) $this->logPath);

        return match (true) {
            $path === '' => $this->logsDir.'/engine.log',
            str_starts_with($path, '/') => $path,
            default => $this->projectDir.'/'.$path,
        };
    }

    /**
     * Spawn the engine and wait until it answers /health.
     *
     * @param string|null $requestedBy Written to the engine log so an operator
     *                                 reading it later knows where the start came from
     */
    public function start(?string $requestedBy = null): EngineStartOutcome
    {
        if (!$this->isConfigured()) {
            return EngineStartOutcome::failed(
                'Este despliegue no define cómo arrancar el motor (ENGINE_START_COMMAND).'
            );
        }

        if ($this->engine->isHealthy()) {
            return EngineStartOutcome::ok('El motor ya estaba en marcha.');
        }

        // Two administrators pressing the button at once would leave two uvicorns
        // fighting over port 8001; the second attempt waits its turn instead.
        $lock = @fopen($this->lockFile(), 'c');

        if ($lock === false) {
            return EngineStartOutcome::failed('No se pudo escribir en var/; revisa los permisos del despliegue.');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return EngineStartOutcome::failed('Ya hay un arranque en curso. Espera unos segundos y vuelve a revisar.');
        }

        try {
            return $this->spawn($requestedBy);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function spawn(?string $requestedBy): EngineStartOutcome
    {
        @unlink($this->pidFile());

        // Best effort: when systemd owns the log file this user may only read it,
        // and losing the header line is no reason to refuse to start the engine.
        @file_put_contents($this->logFile(), sprintf(
            "\n── %s · arranque solicitado desde SIAB%s ──\n",
            date('Y-m-d H:i:s'),
            $requestedBy !== null ? ' por '.$requestedBy : '',
        ), FILE_APPEND);

        // `echo $$` followed by `exec` leaves the engine's *own* pid in the file:
        // the shell replaces itself with uvicorn rather than staying on as a parent,
        // so a pid that has disappeared means the engine itself died.
        $script = sprintf(
            'echo $$ > %s; exec %s >> %s 2>&1',
            escapeshellarg($this->pidFile()),
            $this->command(),
            escapeshellarg($this->logFile()),
        );

        // setsid puts it in its own session, so it survives php-fpm recycling the
        // worker that started it. nohup is the fallback where setsid is missing.
        $detach = (new ExecutableFinder())->find('setsid') !== null ? 'setsid' : 'nohup';

        $process = Process::fromShellCommandline(
            sprintf('%s /bin/sh -c %s < /dev/null > /dev/null 2>&1 &', $detach, escapeshellarg($script)),
            // No cwd when the engine lives somewhere this user cannot read: a
            // delegated start (systemctl) does not need one, and the unit file
            // carries its own WorkingDirectory.
            $this->ownsTheProcess() ? $this->workingDirectory() : null,
            timeout: 10,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->error('Engine start failed to spawn: {msg}', ['msg' => $e->getMessage()]);

            return EngineStartOutcome::failed('No se pudo lanzar el proceso: '.$e->getMessage());
        }

        if (!$process->isSuccessful()) {
            return EngineStartOutcome::failed(
                'No se pudo lanzar el proceso.',
                trim($process->getErrorOutput()),
            );
        }

        $this->logger->notice('Engine start requested from SIAB by {user}', ['user' => $requestedBy ?? 'desconocido']);

        return $this->waitUntilReady();
    }

    private function waitUntilReady(): EngineStartOutcome
    {
        $deadline = microtime(true) + self::READY_TIMEOUT;
        $graceEndsAt = null;

        while (microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_US);

            if ($this->engine->isHealthy()) {
                return EngineStartOutcome::ok('Motor de análisis en marcha.');
            }

            // What we launched is gone. Spawning uvicorn ourselves, that means it
            // crashed on boot; with `systemctl start` it only means systemd has
            // taken over and the engine is still coming up. Nothing here can tell
            // those apart, so it gets a short grace rather than an instant failure.
            if (!$this->stillBooting()) {
                $graceEndsAt ??= microtime(true) + $this->handoffGrace;

                if (microtime(true) >= $graceEndsAt) {
                    break;
                }
            }
        }

        return EngineStartOutcome::failed(
            'El motor no respondió tras el arranque.',
            $this->logTail(),
        );
    }

    /** False once the process we launched is gone (see waitUntilReady). */
    private function stillBooting(): bool
    {
        $pid = (int) @file_get_contents($this->pidFile());

        // No pid yet (or no posix extension): we cannot tell, so keep waiting.
        if ($pid <= 0 || !function_exists('posix_kill')) {
            return true;
        }

        return @posix_kill($pid, 0);
    }

    /** Last lines of the engine log — where the actual failure is written. */
    public function logTail(int $lines = self::TAIL_LINES): string
    {
        $log = $this->logFile();
        $size = @filesize($log);

        if ($size === false || !is_readable($log)) {
            return '';
        }

        $handle = @fopen($log, 'r');

        if ($handle === false) {
            return '';
        }

        fseek($handle, -min($size, self::TAIL_BYTES), SEEK_END);
        $chunk = (string) fread($handle, self::TAIL_BYTES);
        fclose($handle);

        $rows = preg_split('/\R/', trim($chunk)) ?: [];

        return implode("\n", array_slice($rows, -$lines));
    }

    private function pidFile(): string
    {
        return $this->projectDir.'/var/engine.pid';
    }

    private function lockFile(): string
    {
        return $this->projectDir.'/var/engine.start.lock';
    }
}
