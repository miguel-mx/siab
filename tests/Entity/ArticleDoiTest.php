<?php

namespace App\Tests\Entity;

use App\Entity\Article;
use App\Entity\CitingWork;
use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenAlex returns a resolved "https://doi.org/10.…" while zbMATH and Scopus
 * return the bare "10.…", so both shapes reached the column. The templates then
 * prefixed the resolver unconditionally and produced
 * https://doi.org/https://doi.org/10.1016/j.apal.2017.06.001 — a dead link on
 * every article whose DOI came from OpenAlex, which was most of them.
 */
final class ArticleDoiTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function storedShapes(): iterable
    {
        yield 'resolved by OpenAlex' => ['https://doi.org/10.1016/j.apal.2017.06.001', '10.1016/j.apal.2017.06.001'];
        yield 'bare from zbMATH' => ['10.1016/j.apal.2017.06.001', '10.1016/j.apal.2017.06.001'];
        yield 'legacy dx host' => ['http://dx.doi.org/10.1234/abc', '10.1234/abc'];
        yield 'uppercase host' => ['HTTPS://DOI.ORG/10.1234/abc', '10.1234/abc'];
        yield 'doi: scheme' => ['doi:10.1234/abc', '10.1234/abc'];
        yield 'surrounding space' => ['  10.1234/abc  ', '10.1234/abc'];
        // Parentheses are legal in a DOI and must survive untouched.
        yield 'elsevier parentheses' => ['10.1016/s0166-8641(01)00068-2', '10.1016/s0166-8641(01)00068-2'];
    }

    #[DataProvider('storedShapes')]
    public function testNormalisesEveryShapeToTheBareDoi(string $stored, string $expected): void
    {
        self::assertSame($expected, Article::normalizeDoi($stored));
    }

    public function testTreatsEmptyAsAbsent(): void
    {
        self::assertNull(Article::normalizeDoi(null));
        self::assertNull(Article::normalizeDoi(''));
        self::assertNull(Article::normalizeDoi('   '));
    }

    public function testBothEntitiesNormaliseOnWrite(): void
    {
        $article = (new Article())->setDoi('https://doi.org/10.1016/j.apal.2017.06.001');
        $citing = (new CitingWork())->setDoi('https://doi.org/10.1016/j.apal.2017.06.001');

        self::assertSame('10.1016/j.apal.2017.06.001', $article->getDoi());
        self::assertSame('10.1016/j.apal.2017.06.001', $citing->getDoi());
    }

    /**
     * The filter has to keep serving the rows written before the normalisation,
     * which still hold the resolved form — hence "either shape, one link".
     */
    #[DataProvider('storedShapes')]
    public function testBuildsOneResolverPrefixFromEitherShape(string $stored, string $expected): void
    {
        self::assertSame('https://doi.org/' . $expected, (new AppExtension())->doiUrl($stored));
    }

    public function testOffersNoLinkWhenThereIsNoDoiToResolve(): void
    {
        $extension = new AppExtension();

        self::assertNull($extension->doiUrl(null));
        self::assertNull($extension->doiUrl(''));
        // Not a DOI: linking it would land the reader on a doi.org error page.
        self::assertNull($extension->doiUrl('no-es-un-doi'));
    }
}
