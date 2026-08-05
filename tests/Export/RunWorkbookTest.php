<?php

namespace App\Tests\Export;

use App\Entity\AnalysisRun;
use App\Entity\Article;
use App\Entity\CitingWork;
use App\Entity\Researcher;
use App\Enum\RunStatus;
use App\Export\RunWorkbook;
use App\Twig\AppExtension;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * The workbook the library staff validate a run in.
 *
 * Written through a real save/reload rather than asserted on the in-memory
 * Spreadsheet: what matters is what Excel opens, and the two differ — a title
 * beginning with "=" survives the object graph intact and only becomes a formula
 * once the file is written and read back.
 */
final class RunWorkbookTest extends TestCase
{
    private function completedRun(): AnalysisRun
    {
        $researcher = (new Researcher())
            ->setDisplayName('Michael Hrušák')
            ->setSlug('michael-hrusak')
            ->setOrcid('0000-0002-1692-2216')
            ->setOpenalexId('https://openalex.org/A5065080063')
            ->setField('Topología general');

        $run = (new AnalysisRun())
            ->setSlug('michael-hrusak-20260729-2354')
            ->setResearcher($researcher)
            ->setStatus(RunStatus::COMPLETED)
            ->setSources('openalex,zbmath')
            ->setTotalArticles(2)
            ->setTotalTypeA(6)
            ->setTotalTypeB(3)
            ->setTotalSelf(1)
            ->setFlags(['OpenAlex informa 12 citas y sólo se clasificaron 10.'])
            ->setNotes(['zbMATH aportó 2 citas que OpenAlex no tenía.']);

        $first = (new Article())
            ->setTitle('Malykhin&rsquo;s problem')
            ->setAuthors('Hrušák, M.; Ramos-García, U.')
            ->setYear(2014)
            ->setJournal('Advances in Mathematics')
            ->setDoi('https://doi.org/10.1016/j.aim.2014.05.013')
            ->setOpenalexId('W2963891042')
            ->setOpenalexCitedByCount(12)
            ->setZbmathCitedByCount(4)
            ->setCitesTypeA(6)
            ->setCitesTypeB(3)
            ->setCitesSelf(1);

        // The hostile shape: Excel reads a leading "=" as a formula, and this is a
        // real title style in mathematics ("=1 implies…").
        $second = (new Article())
            ->setTitle('=1 and the ultrafilter number')
            ->setYear(2020)
            ->setDoi('sin-doi');

        $run->addArticle($first);
        $run->addArticle($second);

        $first->addCitingWork((new CitingWork())
            ->setTitle('A cardinal invariant of the continuum')
            ->setAuthors(['Dow, A.', 'Shelah, S.'])
            ->setYear(2019)
            ->setDoi('10.1007/s00153-019-00666-x')
            ->setSource('openalex')
            ->setClassification('A'));

        $first->addCitingWork((new CitingWork())
            ->setTitle('Parametrized diamonds')
            ->setAuthors(['Hrušák, M.'])
            ->setYear(2018)
            ->setSource('zbmath')
            ->setClassification('self'));

        // Never classified by the engine: counts towards no total, so it must not be
        // able to hide among the Tipo A rows.
        $first->addCitingWork((new CitingWork())
            ->setTitle('Unclassified citing work')
            ->setYear(2021)
            ->setSource('openalex'));

        return $run;
    }

    /** Build, write and read back — the sheets as Excel would present them. */
    private function reload(AnalysisRun $run): Spreadsheet
    {
        $workbook = new RunWorkbook(new AppExtension());
        $path = tempnam(sys_get_temp_dir(), 'siab').'.xlsx';

        try {
            (new XlsxWriter($workbook->build($run, $run->getArticles()->toArray())))->save($path);

            return (new XlsxReader())->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testCarriesTheFourSheetsInReadingOrder(): void
    {
        self::assertSame(
            ['Resumen', 'Artículos', 'Citas', 'Incidencias'],
            $this->reload($this->completedRun())->getSheetNames(),
        );
    }

    /**
     * A title starting with "=" must arrive as text. Left to Excel's own type
     * detection it lands in the cell as #NAME? — the row silently stops being the
     * article it describes, in a sheet whose only job is checking that the articles
     * are all there.
     */
    public function testWritesTitlesAsTextEvenWhenTheyLookLikeFormulas(): void
    {
        $articles = $this->reload($this->completedRun())->getSheetByName('Artículos');

        self::assertNotNull($articles);
        self::assertSame('=1 and the ultrafilter number', $articles->getCell('B3')->getValue());
        self::assertFalse($articles->getCell('B3')->isFormula());
    }

    public function testWritesOneRowPerCitingWorkWithItsClassificationSpelledOut(): void
    {
        $citations = $this->reload($this->completedRun())->getSheetByName('Citas');

        self::assertNotNull($citations);
        // Three citations, plus the header row.
        self::assertSame(4, $citations->getHighestDataRow());

        self::assertSame('A cardinal invariant of the continuum', $citations->getCell('D2')->getValue());
        self::assertSame('Dow, A.; Shelah, S.', $citations->getCell('E2')->getValue());
        self::assertSame('OpenAlex', $citations->getCell('H2')->getValue());
        self::assertSame('Tipo A — externa', $citations->getCell('I2')->getValue());

        self::assertSame('zbMATH', $citations->getCell('H3')->getValue());
        self::assertSame('Autocita', $citations->getCell('I3')->getValue());

        self::assertSame('Sin clasificar', $citations->getCell('I4')->getValue());
    }

    /** The reviewer's two columns are the reason this is a spreadsheet; they ship empty. */
    public function testLeavesTheVerdictColumnsBlankForTheReviewer(): void
    {
        $citations = $this->reload($this->completedRun())->getSheetByName('Citas');

        self::assertNotNull($citations);
        self::assertSame('¿Correcta?', $citations->getCell('J1')->getValue());
        self::assertSame('Observaciones', $citations->getCell('K1')->getValue());

        foreach (['J2', 'K2', 'J3', 'K3'] as $cell) {
            self::assertNull($citations->getCell($cell)->getValue(), "{$cell} debería quedar vacía");
        }
    }

    /**
     * A DOI must be clickable: the validation is "open the citation and look at it",
     * and a reviewer who has to paste 200 DOIs by hand will not do it 200 times.
     */
    public function testHyperlinksDoisAndLeavesTheRestAsPlainText(): void
    {
        $articles = $this->reload($this->completedRun())->getSheetByName('Artículos');

        self::assertNotNull($articles);
        // Stored resolved by OpenAlex, shown bare, linked through the resolver.
        self::assertSame('10.1016/j.aim.2014.05.013', $articles->getCell('F2')->getValue());
        self::assertSame(
            'https://doi.org/10.1016/j.aim.2014.05.013',
            $articles->getCell('F2')->getHyperlink()->getUrl(),
        );

        // Not a DOI, so nothing to link to — and no dead link either.
        self::assertSame('', $articles->getCell('F3')->getHyperlink()->getUrl());
    }

    /**
     * Rows written before Article::normalizeDoi() hold the resolved URL, and
     * Doctrine hydrates the column straight into the property without passing
     * through the setter that would normalise it — so most runs on file still carry
     * that shape. Both must reach the sheet looking the same; a column mixing
     * "10.1016/…" with "https://doi.org/10.1016/…" cannot be scanned down.
     */
    public function testShowsLegacyUrlShapedDoisBareLikeEveryOther(): void
    {
        $run = $this->completedRun();
        $legacy = $run->getArticles()->last();

        // Straight onto the property, the way a hydrated legacy row arrives.
        $property = new \ReflectionProperty(Article::class, 'doi');
        $property->setValue($legacy, 'https://doi.org/10.1090/s0002-9947-03-03446-9');

        $articles = $this->reload($run)->getSheetByName('Artículos');

        self::assertNotNull($articles);
        self::assertSame('10.1090/s0002-9947-03-03446-9', $articles->getCell('F3')->getValue());
        self::assertSame(
            'https://doi.org/10.1090/s0002-9947-03-03446-9',
            $articles->getCell('F3')->getHyperlink()->getUrl(),
        );
    }

    /**
     * OpenAlex returns titles carrying inline markup and HTML entities. The browser
     * hides both; a cell prints them raw, and a reviewer matching the row against a
     * paper in front of them would be reading "Malykhin&rsquo;s problem".
     */
    public function testStripsMarkupAndDecodesEntitiesInTitles(): void
    {
        $articles = $this->reload($this->completedRun())->getSheetByName('Artículos');

        self::assertNotNull($articles);
        self::assertSame('Malykhin’s problem', $articles->getCell('B2')->getValue());
    }

    /** Missing and zero are different answers; a source with no record must not read as zero citations. */
    public function testDistinguishesAnAbsentCountFromZero(): void
    {
        $articles = $this->reload($this->completedRun())->getSheetByName('Artículos');

        self::assertNotNull($articles);
        self::assertSame(4, $articles->getCell('N2')->getValue(), 'zbMATH informó 4 citas');
        self::assertSame('—', $articles->getCell('L2')->getValue(), 'Scopus no fue consultado');
    }

    public function testKeepsTheRunsOwnTotalsAndTheirShareOfTheWhole(): void
    {
        $summary = $this->reload($this->completedRun())->getSheetByName('Resumen');

        self::assertNotNull($summary);

        // Read by label rather than by coordinate: the summary is a list of pairs
        // whose rows shift whenever one is added, and a test that pins row numbers
        // would fail on an edit that changed nothing it is checking.
        $value = [];
        $share = [];
        foreach ($summary->getRowIterator() as $row) {
            $number = $row->getRowIndex();
            $label = (string) $summary->getCell("A{$number}")->getValue();
            $value[$label] = $summary->getCell("B{$number}")->getValue();
            $share[$label] = $summary->getCell("C{$number}")->getValue();
        }

        self::assertSame('0000-0002-1692-2216', $value['ORCID']);
        // Normalised on the way into the entity: the sheet shows the bare id.
        self::assertSame('A5065080063', $value['ID de OpenAlex']);
        self::assertSame('OpenAlex, zbMATH', $value['Fuentes consultadas']);
        self::assertSame(10, $value['Citas contabilizadas']);
        self::assertSame(6, $value['Tipo A — externa']);
        self::assertSame(1, $value['Autocita']);
        // A fraction, not the string "60%", so the cell can still be computed with.
        self::assertEqualsWithDelta(0.6, $share['Tipo A — externa'], 0.0001);
        self::assertEqualsWithDelta(0.1, $share['Autocita'], 0.0001);
    }

    /**
     * Flags and notes travel with the figures: a shortfall against OpenAlex's own
     * count is precisely what the validation is hunting for, and it exists nowhere
     * else in the workbook.
     */
    public function testCarriesTheEnginesFlagsAndNotes(): void
    {
        $issues = $this->reload($this->completedRun())->getSheetByName('Incidencias');

        self::assertNotNull($issues);
        self::assertSame('Alerta', $issues->getCell('A2')->getValue());
        self::assertStringContainsString('sólo se clasificaron 10', (string) $issues->getCell('B2')->getValue());
        self::assertSame('Nota', $issues->getCell('A3')->getValue());
        self::assertStringContainsString('zbMATH aportó', (string) $issues->getCell('B3')->getValue());
    }

    /** An empty sheet reads like a broken export, so a clean run says so in words. */
    public function testSaysSoWhenTheEngineReportedNothing(): void
    {
        $run = $this->completedRun()->setFlags([])->setNotes([]);
        $issues = $this->reload($run)->getSheetByName('Incidencias');

        self::assertNotNull($issues);
        self::assertStringContainsString('no reportó alertas ni notas', (string) $issues->getCell('B2')->getValue());
    }

    public function testNamesTheFileAfterTheRun(): void
    {
        self::assertSame(
            'siab-michael-hrusak-20260729-2354.xlsx',
            (new RunWorkbook(new AppExtension()))->filename($this->completedRun()),
        );
    }
}
