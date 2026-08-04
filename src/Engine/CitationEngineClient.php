<?php

namespace App\Engine;

use App\Engine\Dto\AnalysisResultDto;
use App\Engine\Dto\ResolveResult;
use App\Entity\Researcher;
use App\Enum\AnalysisSource;
use App\Enum\SettingKey;
use App\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transport-only client for the Python citation engine (../citas-engine).
 * Returns DTOs; never touches Doctrine — persistence lives in AnalysisResultMapper.
 *
 * SIAB owns the engine's operational settings (see /admin/configuracion): the Ollama
 * server, the model and the fetch limits travel with every call, so an administrator
 * can change them without anyone restarting uvicorn.
 */
final class CitationEngineClient implements EngineHealthProbe
{
    public function __construct(
        #[Autowire(service: 'citation_engine.client')]
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * The engine-facing configuration sent on every call that can use it.
     *
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        return [
            'ollama_base' => $this->settings->get(SettingKey::OLLAMA_BASE),
            'ollama_model' => $this->settings->get(SettingKey::OLLAMA_MODEL),
            'count_tolerance' => $this->settings->getFloat(SettingKey::COUNT_TOLERANCE),
        ];
    }

    /**
     * API keys for the optional sources, decrypted from the settings store.
     *
     * Omitted entirely when blank so the engine reports "sin clave configurada"
     * rather than treating an empty string as a key and getting a 401 — and
     * omitted just the same when the run did not ask for that source, since
     * withholding the key is how the engine is told to skip Scopus or WoS.
     *
     * @param list<string>|null $sources null means "whatever is configured"
     *
     * @return array<string,string>
     */
    private function sourceCredentials(?array $sources): array
    {
        $wanted = static fn (AnalysisSource $s): bool => $sources === null || in_array($s->value, $sources, true);

        return array_filter([
            'scopus_api_key' => $wanted(AnalysisSource::SCOPUS) ? $this->settings->get(SettingKey::SCOPUS_API_KEY) : '',
            'wos_api_key' => $wanted(AnalysisSource::WOS) ? $this->settings->get(SettingKey::WOS_API_KEY) : '',
        ], static fn (string $v) => $v !== '');
    }

    /**
     * The researcher's per-source author identifiers.
     *
     * Only non-empty values are sent. A blank identifier makes the engine fall back
     * to ORCID or skip the source, which is the safe behaviour: a wrong AU-ID or
     * recid silently attributes someone else's works to this researcher.
     *
     * @return array<string,string>
     */
    private function sourceIdentifiers(?Researcher $researcher): array
    {
        if ($researcher === null) {
            return [];
        }

        return array_filter([
            'scopus_author_id' => (string) $researcher->getScopusId(),
            'zbmath_author_code' => (string) $researcher->getZbmathCode(),
            'inspire_author_recid' => (string) $researcher->getInspireRecid(),
        ], static fn (string $v) => trim($v) !== '');
    }

    /**
     * Liveness + effective engine config. Returns the decoded /health body.
     *
     * @return array{status:string, openalex_base?:string, model?:string}
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * Deep health check: the engine probes its own dependencies (OpenAlex, Ollama)
     * and reports the optional sources' configuration. Time-boxed on the engine side.
     *
     * @return array{checked_at:string, services:list<array<string,mixed>>}
     */
    public function services(): array
    {
        // The probe must target the Ollama SIAB will actually use, or the dashboard
        // could show a healthy server that no report is ever sent to.
        //
        // Scopus/WoS flags are not sent: the panel no longer shows those rows, and
        // whether their keys work is answered by "Probar clave" in the admin screen,
        // which asks the service directly. The engine still accepts the flags for any
        // caller that does want an honest answer about them.
        return $this->request('GET', '/health/services?'.http_build_query([
            'ollama_base' => $this->settings->get(SettingKey::OLLAMA_BASE),
            'ollama_model' => $this->settings->get(SettingKey::OLLAMA_MODEL),
        ]));
    }

    public function isHealthy(): bool
    {
        try {
            return ($this->health()['status'] ?? null) === 'ok';
        } catch (CitationEngineException) {
            return false;
        }
    }

    /**
     * Resolve a researcher by OpenAlex ID, ORCID, or name.
     */
    public function resolve(string $query): ResolveResult
    {
        $data = $this->request('POST', '/resolve', ['query' => $query]);

        return ResolveResult::fromArray($data);
    }

    /**
     * Run the full citation pipeline for a resolved author id (OpenAlex ID or ORCID).
     * This is the slow call — it is meant to run from a Messenger worker.
     */
    public function analyze(
        string $authorId,
        bool $wantReport = false,
        string $reportLanguage = 'es',
        ?int $maxWorks = null,
        ?int $maxCitingPerWork = null,
        ?Researcher $researcher = null,
        ?string $jobId = null,
        ?array $sources = null,
        ?array $comparison = null,
    ): AnalysisResultDto {
        $payload = array_filter([
            'author_id' => $authorId,
            'want_report' => $wantReport,
            'report_language' => $reportLanguage,
            // Lets the run's page ask the engine where it has got to while this
            // request — which takes minutes — is still open. See progress().
            'job_id' => $jobId,
            // Only used when $wantReport; see report().
            'comparison' => $comparison,
            // An explicit per-run limit wins; otherwise the administrator's setting.
            'max_works' => $maxWorks ?? $this->settings->getInt(SettingKey::MAX_WORKS),
            'max_citing_per_work' => $maxCitingPerWork ?? $this->settings->getInt(SettingKey::MAX_CITING_PER_WORK),
        ], static fn ($v) => $v !== null)
            // zbMATH and INSPIRE have explicit switches engine-side; Scopus and WoS
            // are excluded by withholding their key (see sourceCredentials).
            + ($sources === null ? [] : [
                'use_zbmath' => in_array(AnalysisSource::ZBMATH->value, $sources, true),
                'use_inspire' => in_array(AnalysisSource::INSPIRE->value, $sources, true),
            ])
            + $this->overrides()
            + $this->sourceCredentials($sources)
            + $this->sourceIdentifiers($researcher);

        $data = $this->request('POST', '/analyze', $payload);

        return AnalysisResultDto::fromResponse($data);
    }

    /**
     * Ask a source whether it accepts the key SIAB has stored for it.
     *
     * The engine owns the vendor clients, so it is the only place that knows which
     * endpoint and which header each service wants. The key is sent for that one
     * request and is neither logged nor echoed back — only the verdict comes home.
     *
     * @return array{ok:bool, state:string, message:string}
     */
    public function checkSourceKey(string $source, string $apiKey): array
    {
        try {
            return $this->request('POST', '/health/source-key', [
                'source' => $source,
                'api_key' => $apiKey,
            ], timeout: 30);
        } catch (CitationEngineException $e) {
            return ['ok' => false, 'state' => 'down', 'message' => $e->getMessage()];
        }
    }

    /**
     * Where a running analysis has got to, or null when the engine has nothing to
     * say about it.
     *
     * Called from a web request that a person is waiting on, so it is given a short
     * timeout of its own — the scoped client allows /analyze ten minutes, which is
     * the last thing a page poll should inherit. Any failure is "no progress to
     * show": an analysis in flight must never be reported as broken because the
     * decoration could not be fetched.
     *
     * @return array{phase:string, done:int, total:int, percent:?int, elapsed_seconds:float}|null
     */
    public function progress(string $jobId): ?array
    {
        try {
            $data = $this->http->request('GET', '/progress/'.rawurlencode($jobId), [
                'timeout' => 3,
                'max_duration' => 5,
            ])->toArray();
        } catch (\Throwable $e) {
            $this->logger->debug('No progress for job {job}: {msg}', ['job' => $jobId, 'msg' => $e->getMessage()]);

            return null;
        }

        return ($data['running'] ?? false) === true ? $data : null;
    }

    /**
     * Generate a narrative report from an already-computed result payload.
     * Requires Gemma4/Ollama on the engine side.
     *
     * @param array $resultPayload The engine's `result` object (e.g. AnalysisRun.rawSnapshot)
     * @return array{report:string, context:array}
     */
    public function report(array $resultPayload, string $language = 'es', ?array $comparison = null): array
    {
        return $this->request('POST', '/report', array_filter([
            'result' => $resultPayload,
            'language' => $language,
            // Omitted when there is no earlier run: the engine then leaves the
            // comparison section out altogether, instead of the model announcing
            // that this is the first analysis for someone who has a dozen.
            'comparison' => $comparison,
        ], static fn ($v) => $v !== null) + $this->overrides());
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     */
    /**
     * @param int|null $timeout Seconds, for the calls a person is waiting on. The
     *                          scoped client allows /analyze ten minutes, which no
     *                          admin screen should ever inherit.
     */
    private function request(string $method, string $path, ?array $json = null, ?int $timeout = null): array
    {
        try {
            // Overrides the scoped client's compiled-in base_uri, so the engine's
            // address is editable at runtime like every other engine setting.
            $options = ['base_uri' => $this->settings->get(SettingKey::ENGINE_URL)];
            if ($json !== null) {
                $options['json'] = $json;
            }
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
                $options['max_duration'] = $timeout + 5;
            }

            $response = $this->http->request($method, $path, $options);
            $status = $response->getStatusCode();

            if ($status >= 400) {
                // FastAPI/Starlette errors carry a `detail` field.
                $detail = null;
                try {
                    $detail = $response->toArray(false)['detail'] ?? null;
                } catch (HttpExceptionInterface) {
                    // non-JSON body — leave detail null
                }
                $detail = is_array($detail) ? json_encode($detail) : $detail;

                $this->logger->error('Citation engine returned {status} for {method} {path}: {detail}', [
                    'status' => $status, 'method' => $method, 'path' => $path, 'detail' => $detail,
                ]);

                throw new CitationEngineException(
                    sprintf('El motor de análisis respondió %d en %s.', $status, $path),
                    statusCode: $status,
                    detail: is_string($detail) ? $detail : null,
                );
            }

            return $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Citation engine unreachable on {method} {path}: {msg}', [
                'method' => $method, 'path' => $path, 'msg' => $e->getMessage(),
            ]);

            throw new CitationEngineException(
                sprintf('No se pudo contactar el motor de análisis en %s.', $path),
                previous: $e,
            );
        }
    }
}
