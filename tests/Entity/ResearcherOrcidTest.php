<?php

namespace App\Tests\Entity;

use App\Entity\Researcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ORCID check character is the only thing standing between a typo and a
 * researcher's figures being computed from someone else's publications, so it is
 * worth proving the arithmetic rather than assuming it.
 */
final class ResearcherOrcidTest extends TestCase
{
    public function testAcceptsARealOrcid(): void
    {
        self::assertTrue(Researcher::isValidOrcid('0000-0002-1825-0097'));
    }

    public function testAcceptsTheXCheckCharacter(): void
    {
        // Check character 10 is written X — the one non-digit ORCID allows.
        self::assertTrue(Researcher::isValidOrcid('0000-0002-1694-233X'));
    }

    public function testRejectsAWrongCheckCharacter(): void
    {
        // Same digits as the valid one above, last character off by one.
        self::assertFalse(Researcher::isValidOrcid('0000-0002-1825-0098'));
    }

    public function testRejectsATransposition(): void
    {
        // 1825 → 1852: the shape is still right, only the checksum catches it.
        self::assertFalse(Researcher::isValidOrcid('0000-0002-1852-0097'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'too short' => ['0000-0002-1825'];
        yield 'no dashes' => ['0000000218250097'];
        yield 'letters' => ['ABCD-0002-1825-0097'];
        yield 'X in the wrong place' => ['0000-000X-1825-0097'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedInput(string $value): void
    {
        self::assertFalse(Researcher::isValidOrcid($value));
    }

    public function testNormalizationAcceptsWhatSomeoneWouldPaste(): void
    {
        foreach ([
            'https://orcid.org/0000-0002-1694-233X',
            'http://ORCID.org/0000-0002-1694-233x',
            '  0000-0002-1694-233x  ',
        ] as $pasted) {
            $normalized = Researcher::normalizeOrcid($pasted);

            self::assertSame('0000-0002-1694-233X', $normalized, $pasted);
            self::assertTrue(Researcher::isValidOrcid((string) $normalized), $pasted);
        }
    }

    public function testBlankMeansNoOrcidRatherThanAnEmptyString(): void
    {
        self::assertNull(Researcher::normalizeOrcid('   '));
        self::assertNull(Researcher::normalizeOrcid(null));
    }

    public function testTheEngineIsQueriedByOrcidBeforeTheOpenalexId(): void
    {
        $researcher = (new Researcher())
            ->setOpenalexId('A5023888391')
            ->setOrcid('0000-0002-1825-0097');

        self::assertSame('0000-0002-1825-0097', $researcher->preferredEngineQuery());
    }

    public function testTheOpenalexIdCarriesResearchersWithNoOrcid(): void
    {
        $researcher = (new Researcher())->setOpenalexId('A5023888391');

        self::assertSame('A5023888391', $researcher->preferredEngineQuery());
    }

    /**
     * The engine resolves an OpenAlex id or an ORCID and treats anything else as a
     * name to search for, so a Scopus AU-ID must never become the query.
     */
    public function testTheScopusIdIsNeverUsedAsTheEngineQuery(): void
    {
        $researcher = (new Researcher())->setScopusId('6602738988');

        self::assertNull($researcher->preferredEngineQuery());
    }
}
