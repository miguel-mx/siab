<?php

namespace App\Tests\Engine;

use App\Engine\AnalysisResultMapper;
use App\Engine\CitationEngineClient;
use App\Entity\AnalysisRun;
use App\Entity\Researcher;
use App\Enum\RunStatus;
use App\Repository\AnalysisRunRepository;
use App\Repository\SettingRepository;
use App\Service\AnalysisRunner;
use App\Service\AuditLog;
use App\Service\SecretBox;
use App\Service\SettingsService;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The report compares only against a previous run classified under the same rule.
 *
 * Type B became a per-article rule; runs from before that overstate B. Comparing a
 * new run with one of them would write up the rule change as a change in the
 * researcher's record, so the comparison is left out — which the engine's prompt
 * already handles by saying nothing about earlier analyses.
 */
final class ReportRuleComparisonTest extends TestCase
{
    /** @return array<string,mixed> the body /report receives for `$run` */
    private function reportBody(AnalysisRun $run, AnalysisRun $previous): array
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
        $em = $this->createStub(EntityManagerInterface::class);

        $runs = $this->createStub(AnalysisRunRepository::class);
        $runs->method('findPreviousCompletedForResearcher')->willReturn($previous);

        $engine = new CitationEngineClient(
            $http,
            new NullLogger(),
            new SettingsService($settings, $em, new SecretBox('test-secret'), 'http://127.0.0.1:8001', null, null),
        );

        (new AnalysisRunner(
            $runs,
            $em,
            $this->createStub(MessageBusInterface::class),
            $engine,
            new AnalysisResultMapper(),
            new SlugGenerator(new AsciiSlugger(), $em),
            new NullLogger(),
            new AuditLog($em, new NullLogger()),
        ))->generateReport($run);

        self::assertSame('texto', $run->getReport(), 'the report itself is still written');

        return $sent;
    }

    private function runUnder(?string $rule): AnalysisRun
    {
        return (new AnalysisRun())
            ->setResearcher(new Researcher())
            ->setStatus(RunStatus::COMPLETED)
            ->setRawSnapshot(['author' => []])
            ->setClassificationRule($rule)
            ->setTotalTypeA(10)
            ->setTotalTypeB(5);
    }

    public function testAPreviousRunUnderTheEarlierRuleIsNotCompared(): void
    {
        $body = $this->reportBody($this->runUnder(AnalysisRun::CLASSIFICATION_RULE), $this->runUnder(null));

        self::assertArrayNotHasKey('comparison', $body);
    }

    public function testAPreviousRunUnderTheSameRuleIsCompared(): void
    {
        $body = $this->reportBody(
            $this->runUnder(AnalysisRun::CLASSIFICATION_RULE),
            $this->runUnder(AnalysisRun::CLASSIFICATION_RULE),
        );

        self::assertSame(5, $body['comparison']['total_citas_tipo_b']);
    }
}
