<?php

namespace App\Tests\Engine;

use App\Engine\CitationEngineClient;
use App\Entity\Setting;
use App\Enum\AnalysisSource;
use App\Repository\SettingRepository;
use App\Service\SecretBox;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exactly what SIAB puts in the body of /analyze for a given source selection.
 *
 * Worth pinning because the two mechanisms are not symmetrical, and neither is
 * visible from the outside: zbMATH and INSPIRE are switched off with a flag, while
 * Scopus and Web of Science are switched off by *withholding the API key*. Send
 * the key by mistake and an excluded source is queried anyway.
 */
final class AnalyzePayloadTest extends TestCase
{
    /**
     * @param list<string>|null $sources
     *
     * @return array<string,mixed> the JSON body the engine would receive
     */
    private function payloadFor(?array $sources): array
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode([
                'result' => ['author' => ['display_name' => 'X', 'openalex_id' => 'A1'], 'articles' => [], 'flags' => []],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $secrets = new SecretBox('test-secret');
        $settings = $this->createStub(SettingRepository::class);
        // Both keys configured, so the only thing deciding whether they are sent is
        // the selection under test.
        $settings->method('findAllIndexed')->willReturn([
            'scopus_api_key' => (new Setting('scopus_api_key'))->setValue($secrets->encrypt('SCOPUS-KEY')),
            'wos_api_key' => (new Setting('wos_api_key'))->setValue($secrets->encrypt('WOS-KEY')),
        ]);

        $client = new CitationEngineClient(
            $http,
            new NullLogger(),
            new SettingsService(
                $settings,
                $this->createStub(EntityManagerInterface::class),
                $secrets,
                'http://127.0.0.1:8001',
                null,
                null,
            ),
        );

        $client->analyze('0000-0002-1692-2216', sources: $sources);

        return $sent;
    }

    public function testExcludingZbmathAndInspireSendsTheirSwitchesOff(): void
    {
        $payload = $this->payloadFor([AnalysisSource::OPENALEX->value]);

        self::assertFalse($payload['use_zbmath']);
        self::assertFalse($payload['use_inspire']);
    }

    public function testIncludingThemSendsTheSwitchesOn(): void
    {
        $payload = $this->payloadFor([
            AnalysisSource::OPENALEX->value,
            AnalysisSource::ZBMATH->value,
            AnalysisSource::INSPIRE->value,
        ]);

        self::assertTrue($payload['use_zbmath']);
        self::assertTrue($payload['use_inspire']);
    }

    /** The asymmetric one: an unticked Scopus must not have its key sent. */
    public function testExcludingScopusWithholdsItsKey(): void
    {
        $payload = $this->payloadFor([AnalysisSource::OPENALEX->value, AnalysisSource::WOS->value]);

        self::assertArrayNotHasKey('scopus_api_key', $payload);
        self::assertSame('WOS-KEY', $payload['wos_api_key'], 'the source that was kept still gets its key');
    }

    public function testIncludingScopusSendsItsKey(): void
    {
        $payload = $this->payloadFor([AnalysisSource::OPENALEX->value, AnalysisSource::SCOPUS->value]);

        self::assertSame('SCOPUS-KEY', $payload['scopus_api_key']);
        self::assertArrayNotHasKey('wos_api_key', $payload);
    }

    /**
     * A run stored before source selection existed passes null, and must keep
     * behaving as it always did: every configured source in play.
     */
    public function testNoSelectionLeavesEveryConfiguredSourceInPlay(): void
    {
        $payload = $this->payloadFor(null);

        self::assertArrayNotHasKey('use_zbmath', $payload, 'the engine default stands');
        self::assertSame('SCOPUS-KEY', $payload['scopus_api_key']);
        self::assertSame('WOS-KEY', $payload['wos_api_key']);
    }
}
