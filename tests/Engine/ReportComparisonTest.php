<?php

namespace App\Tests\Engine;

use App\Engine\CitationEngineClient;
use App\Repository\SettingRepository;
use App\Service\SecretBox;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Whether /report is told about the researcher's earlier analysis.
 *
 * The bug: nothing ever sent one, so the engine's prompt — which said "if it is
 * null, note that this is the first analysis on record" — made every report open
 * with that line, including for researchers with a dozen runs behind them. The
 * absence must be expressed by *omitting* the key, never by sending null.
 */
final class ReportComparisonTest extends TestCase
{
    /** @return array<string,mixed> the body the engine would receive */
    private function bodyFor(?array $comparison): array
    {
        $sent = [];
        $http = new MockHttpClient(function (string $m, string $u, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['report' => 'texto', 'context' => []]), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $settings = $this->createStub(SettingRepository::class);
        $settings->method('findAllIndexed')->willReturn([]);

        (new CitationEngineClient(
            $http,
            new NullLogger(),
            new SettingsService(
                $settings,
                $this->createStub(EntityManagerInterface::class),
                new SecretBox('test-secret'),
                'http://127.0.0.1:8001',
                null,
                null,
            ),
        ))->report(['author' => []], 'es', $comparison);

        return $sent;
    }

    public function testAPreviousRunIsSentSoTheReportCanCompare(): void
    {
        $body = $this->bodyFor([
            'fecha' => '2026-07-30',
            'total_citas' => 1467,
            'total_articulos' => 166,
        ]);

        self::assertSame('2026-07-30', $body['comparison']['fecha']);
        self::assertSame(1467, $body['comparison']['total_citas']);
    }

    /** The key must be absent, not null: a null still invites the model to narrate it. */
    public function testNoPreviousRunOmitsTheKeyEntirely(): void
    {
        $body = $this->bodyFor(null);

        self::assertArrayNotHasKey('comparison', $body);
        self::assertSame('es', $body['language'], 'the rest of the request is unaffected');
    }
}
