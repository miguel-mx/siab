<?php

namespace App\Tests\Engine;

use App\Enum\ArticleSort;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The ordering options as DQL. Checked at the query level because the two things
 * that can go wrong here are silent: an unstable order makes paging repeat and
 * skip rows, and undated articles sorting as year zero would put every one of them
 * at the head of "más antiguos".
 */
final class ArticleSortTest extends TestCase
{
    private function dqlFor(ArticleSort $sort): string
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));
        $qb->select('a')->from('App\Entity\Article', 'a');

        return $sort->applyTo($qb)->getDQL();
    }

    public function testTheDefaultLeadsWithTheArticlesThatCarryTheCount(): void
    {
        self::assertStringContainsString(
            'ORDER BY a.citesTypeA + a.citesTypeB + a.citesSelf DESC',
            $this->dqlFor(ArticleSort::CITED),
        );
    }

    public function testEveryOrderEndsWithTheIdSoPagingIsStable(): void
    {
        foreach (ArticleSort::cases() as $sort) {
            self::assertStringEndsWith('a.id ASC', $this->dqlFor($sort), $sort->value);
        }
    }

    /** Undated articles go last in both directions, never first. */
    public function testUnknownYearsAreNotTreatedAsYearZero(): void
    {
        self::assertStringContainsString('COALESCE(a.year, 9999)', $this->dqlFor(ArticleSort::OLDEST));
        self::assertStringContainsString('COALESCE(a.year, 0)', $this->dqlFor(ArticleSort::NEWEST));
    }

    public function testAnUnknownOrUnsetOrderFallsBackToTheDefault(): void
    {
        // Comes straight from ?orden= in the URL, so it must not be trusted.
        self::assertSame(ArticleSort::CITED, ArticleSort::from_(null));
        self::assertSame(ArticleSort::CITED, ArticleSort::from_(''));
        self::assertSame(ArticleSort::CITED, ArticleSort::from_('; DROP TABLE article'));
        self::assertSame(ArticleSort::NEWEST, ArticleSort::from_('recientes'));
    }

    public function testEveryOptionIsNamedForTheDropdown(): void
    {
        foreach (ArticleSort::cases() as $sort) {
            self::assertNotSame('', $sort->label(), $sort->value);
        }
    }
}
