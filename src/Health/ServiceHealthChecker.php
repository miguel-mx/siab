<?php

namespace App\Health;

use App\Engine\CitationEngineException;
use App\Enum\ServiceState;
use App\Engine\CitationEngineClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Builds the dashboard's service panel. Two things it checks itself — the engine
 * (an HTTP hop from here) and MySQL — plus what the engine relays about OpenAlex,
 * Ollama and the optional sources.
 *
 * Results are cached briefly: every page view would otherwise hit OpenAlex and
 * Ollama, and a dead dependency would make the dashboard as slow as the timeouts.
 */
final class ServiceHealthChecker
{
    public const CACHE_KEY = 'siab.service_health';

    public function __construct(
        private readonly CitationEngineClient $engine,
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(CITATION_ENGINE_URL)%')]
        private readonly string $engineUrl,
        #[Autowire('%env(int:SERVICE_HEALTH_TTL)%')]
        private readonly int $ttl = 30,
    ) {
    }

    /** @param bool $fresh Bypass the cache — the panel's "Revisar ahora" action. */
    public function check(bool $fresh = false): ServiceHealth
    {
        if ($fresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        $data = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter($this->ttl);

            return $this->probe()->toArray();
        });

        return ServiceHealth::fromArray($data);
    }

    private function probe(): ServiceHealth
    {
        $engine = $this->probeEngine();
        $services = [$engine];

        // OpenAlex/Ollama are reachable only *through* the engine; if it is down we
        // report them as unknown rather than guessing they are broken too.
        $relayed = $engine->state->isUsable() ? $this->relayedFromEngine() : [];

        foreach (['openalex' => 'OpenAlex API', 'ollama' => 'Ollama'] as $key => $name) {
            $services[] = $relayed[$key] ?? new ServiceStatus(
                key: $key,
                name: $name,
                detail: 'vía motor de análisis',
                state: ServiceState::UNKNOWN,
                message: 'requiere el motor de análisis',
            );
        }

        $services[] = $this->probeDatabase();

        // Scopus and Web of Science are deliberately absent. The panel answers "can
        // an analysis run right now", and those two never stop one: they are optional
        // enrichment, skipped when no key is configured. Reporting them here only
        // added two permanent "No configurado" rows next to the things that do
        // matter. Their key state, and a button that actually verifies it, live in
        // Administración → Configuración del motor.

        return new ServiceHealth($services, new \DateTimeImmutable());
    }

    private function probeEngine(): ServiceStatus
    {
        $detail = sprintf('FastAPI · %s', $this->hostPort($this->engineUrl));
        $started = microtime(true);

        try {
            $body = $this->engine->health();
        } catch (CitationEngineException $e) {
            return new ServiceStatus('engine', 'Motor de análisis', $detail, ServiceState::DOWN, message: $e->getMessage());
        } catch (\Throwable $e) {
            // Not only engine failures: reaching the engine needs its URL, which is a
            // setting, which is a database read. With the database down that threw
            // straight out of the panel — so the one screen that exists to report a
            // dependency being unreachable went blank precisely then.
            $this->logger->error('Engine probe failed before reaching the engine: {msg}', ['msg' => $e->getMessage()]);

            return new ServiceStatus('engine', 'Motor de análisis', $detail, ServiceState::UNKNOWN, message: 'no se pudo comprobar');
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $state = ($body['status'] ?? null) === 'ok' ? ServiceState::OK : ServiceState::DEGRADED;

        return new ServiceStatus('engine', 'Motor de análisis', $detail, $state, $latency);
    }

    private function probeDatabase(): ServiceStatus
    {
        $started = microtime(true);
        // getDatabase() connects to ask the server which database it is on, so it
        // throws exactly when this probe is most needed. Inside the try, or an
        // unreachable database takes down the whole panel instead of appearing in
        // it as "sin conexión".
        $database = '—';

        try {
            $database = $this->connection->getDatabase() ?? '—';
            $this->connection->executeQuery('SELECT 1');
        } catch (DbalException|\Throwable $e) {
            $this->logger->error('Database health probe failed: {msg}', ['msg' => $e->getMessage()]);

            return new ServiceStatus(
                'database',
                'Base de datos',
                sprintf('MySQL · %s', $database),
                ServiceState::DOWN,
                message: 'sin conexión',
            );
        }

        return new ServiceStatus(
            'database',
            'Base de datos',
            sprintf('MySQL · %s', $database),
            ServiceState::OK,
            (int) round((microtime(true) - $started) * 1000),
        );
    }

    /**
     * Translate the engine's raw probe results into panel rows (it reports facts;
     * the Spanish naming is ours).
     *
     * @return array<string,ServiceStatus>
     */
    private function relayedFromEngine(): array
    {
        try {
            $payload = $this->engine->services();
        } catch (\Throwable $e) {
            // Same reasoning as probeEngine: a missing relay costs two rows, never
            // the panel.
            $this->logger->warning('Deep engine health unavailable: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        // Only what the panel shows. The engine also relays Scopus and WoS; those
        // are dropped here rather than filtered later, so nothing downstream has to
        // remember they exist.
        $names = [
            'openalex' => 'OpenAlex API',
            'ollama' => 'Gemma4 (Ollama)',
        ];

        $out = [];
        foreach ($payload['services'] ?? [] as $service) {
            $key = (string) ($service['key'] ?? '');
            if (!isset($names[$key])) {
                continue;
            }

            $out[$key] = ServiceStatus::fromArray($service + ['name' => $names[$key]]);
        }

        return $out;
    }

    /** "127.0.0.1:8001" → ":8001" when the host is local, else "host:port". */
    private function hostPort(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? $url;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ? ($port ?: $host) : $host.$port;
    }
}
