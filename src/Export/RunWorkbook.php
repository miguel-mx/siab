<?php

namespace App\Export;

use App\Analysis\RunFigures;
use App\Entity\AnalysisRun;
use App\Entity\Article;
use App\Entity\CitingWork;
use App\Twig\AppExtension;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A run's figures as a workbook, for the librarian who has to check them.
 *
 * The narrative report says what the numbers are; this says where each one came
 * from. Validation is a row-by-row job — follow the DOI, decide whether the
 * A/B/autocita call is right, write it down — so the citations sheet carries two
 * empty columns for the reviewer's verdict. That is the whole point of exporting
 * to a spreadsheet rather than to a document: the file is worked in, not read.
 *
 * Figures are never recomputed here. The totals come from the run's own columns
 * and the per-article counts from the articles, exactly as the screen shows them,
 * so an exported number that disagrees with the page would be a bug in one of
 * them rather than a second opinion.
 */
final class RunWorkbook
{
    /** Header fill — light grey, legible when the sheet is printed in black and white. */
    private const HEADER_FILL = 'FFE9ECEF';

    private const LINK_COLOR = 'FF0D6EFD';

    /** How the engine's codes read in the sheet; the same wording as the legend on screen. */
    private const CLASSIFICATIONS = [
        'A' => 'Tipo A — externa',
        'B' => 'Tipo B — coautoría',
        'self' => 'Autocita',
    ];

    private const SOURCES = [
        'openalex' => 'OpenAlex',
        'scopus' => 'Scopus',
        'wos' => 'Web of Science',
        'zbmath' => 'zbMATH',
        'inspire' => 'INSPIRE-HEP',
    ];

    public function __construct(private readonly AppExtension $links)
    {
    }

    /**
     * The download's name. Built from the slug, which already says who and when,
     * so two exports of the same researcher never overwrite each other.
     */
    public function filename(AnalysisRun $run, bool $withoutPreprints = false): string
    {
        // The suffix keeps the two exports of one run apart in a downloads folder,
        // where the file name is all there is to tell them by.
        return sprintf('siab-%s%s.xlsx', $run->getSlug(), $withoutPreprints ? '-sin-preprints' : '');
    }

    /**
     * @param Article[]  $articles         the run's articles, with their citing works already
     *                                     loaded — see ArticleRepository::findAllForRunWithCitations
     * @param RunFigures $figures          totals over exactly those articles
     * @param bool       $withoutPreprints whether preprints were left out of both
     */
    public function build(
        AnalysisRun $run,
        array $articles,
        ?RunFigures $figures = null,
        bool $withoutPreprints = false,
    ): Spreadsheet {
        $figures ??= RunFigures::stored($run);

        $book = new Spreadsheet();
        $book->getProperties()
            ->setTitle(sprintf('Análisis de citas — %s', $run->getResearcher()->getDisplayName()))
            ->setSubject(sprintf('SIAB %s', (string) $run->getSlug()))
            ->setCreator('SIAB');

        $this->summarySheet($book->getActiveSheet(), $run, $figures, $withoutPreprints);
        $this->articlesSheet($book->createSheet(), $articles);
        $this->citationsSheet($book->createSheet(), $articles);
        $this->issuesSheet($book->createSheet(), $run);

        // Opens on the summary rather than on whichever sheet was written last.
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Who was analysed, under which identifiers, and what the run concluded.
     *
     * The identifiers are here because they are the first thing to check: figures
     * assembled under a wrong Scopus AU-ID are wrong in a way no amount of reading
     * the citation list will reveal.
     */
    private function summarySheet(
        Worksheet $sheet,
        AnalysisRun $run,
        RunFigures $figures,
        bool $withoutPreprints,
    ): void {
        $sheet->setTitle('Resumen');

        $researcher = $run->getResearcher();
        $total = $figures->getTotalCitations();

        $row = 1;
        $section = function (string $title) use ($sheet, &$row): void {
            $sheet->setCellValueExplicit("A{$row}", $title, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:B{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
            ++$row;
        };
        $pair = function (string $label, string|int|null $value) use ($sheet, &$row): void {
            $sheet->setCellValueExplicit("A{$row}", $label, DataType::TYPE_STRING);

            if (is_int($value)) {
                $sheet->setCellValue("B{$row}", $value);
            } else {
                $sheet->setCellValueExplicit("B{$row}", $value ?? '—', DataType::TYPE_STRING);
            }

            ++$row;
        };

        $section('Investigador');
        $pair('Nombre', $researcher->getDisplayName());
        $pair('Área', $researcher->getField());
        $pair('ORCID', $researcher->getOrcid());
        $pair('ID de OpenAlex', $researcher->getOpenalexId());
        $pair('AU-ID de Scopus', $researcher->getScopusId());
        $pair('Código zbMATH', $researcher->getZbmathCode());
        $pair('Recid de INSPIRE-HEP', $researcher->getInspireRecid());
        ++$row;

        $section('Análisis');
        $pair('Identificador', $run->getSlug());
        $pair('Estado', $run->getStatus()->label());
        $pair('Solicitado', $this->moment($run->getCreatedAt()));
        $pair('Terminado', $this->moment($run->getFinishedAt()));
        $pair('Fuentes consultadas', implode(', ', $run->getSourceLabels()));
        $pair('Marca de tiempo del motor', $run->getRunTimestamp());
        $pair('Solicitó', $run->getOwner()?->getDisplayName() ?? $run->getOwner()?->getEmail());
        ++$row;

        $section('Resultados');
        // Said outright, and before the numbers: a workbook is read away from the
        // screen that produced it, so nothing else would tell the reader that these
        // totals cover part of the run. The run's full figures follow, so a reviewer
        // can always see what was left out and by how much.
        $pair('Alcance', $withoutPreprints
            ? 'Sin preprints — se excluyeron los trabajos alojados en repositorios (arXiv, bioRxiv…)'
            : 'Análisis completo — incluye preprints');
        $pair('Artículos', $figures->getTotalArticles());
        $pair('Citas contabilizadas', $total);

        if ($withoutPreprints) {
            $pair('Artículos del análisis completo', $run->getTotalArticles());
            $pair('Citas del análisis completo', $run->getTotalCitations());
        }

        ++$row;

        // The breakdown gets its own header row so the percentages have a column
        // heading — as loose label/value pairs nothing said what the third number was.
        $this->header($sheet, $row, ['Clasificación', 'Citas', '% del total']);
        ++$row;

        foreach ([
            self::CLASSIFICATIONS['A'] => $figures->getTotalTypeA(),
            self::CLASSIFICATIONS['B'] => $figures->getTotalTypeB(),
            self::CLASSIFICATIONS['self'] => $figures->getTotalSelf(),
        ] as $label => $count) {
            $sheet->setCellValueExplicit("A{$row}", $label, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $count);
            // Written as a real fraction with a percentage format, so the cell can be
            // charted or summed instead of being the text "50.2%".
            $sheet->setCellValue("C{$row}", $total > 0 ? $count / $total : 0);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('0.0%');
            ++$row;
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(46);
        $sheet->getColumnDimension('C')->setWidth(14);
    }

    /**
     * One row per article, with the per-source counts beside the classification.
     *
     * The counts are what a shortfall looks like from the inside: when OpenAlex
     * reports 12 citations for a paper and only 9 were classified, the two columns
     * sit side by side and the gap is visible without opening the citations sheet.
     *
     * @param Article[] $articles
     */
    private function articlesSheet(Worksheet $sheet, array $articles): void
    {
        $sheet->setTitle('Artículos');

        $this->header($sheet, 1, [
            '#', 'Título', 'Autores', 'Año', 'Revista o publicación', 'DOI', 'Tipo de trabajo',
            'Tipo A', 'Tipo B', 'Autocitas', 'Citas clasificadas',
            'OpenAlex', 'Scopus', 'Web of Science', 'zbMATH', 'INSPIRE-HEP',
            'ID de OpenAlex',
        ]);

        $row = 2;
        foreach ($articles as $index => $article) {
            $classified = $article->getCitesTypeA() + $article->getCitesTypeB() + $article->getCitesSelf();

            $sheet->setCellValue("A{$row}", $index + 1);
            $this->text($sheet, "B{$row}", $this->plainTitle($article->getTitle()));
            $this->text($sheet, "C{$row}", $article->getAuthors());
            $this->number($sheet, "D{$row}", $article->getYear());
            $this->text($sheet, "E{$row}", $article->getJournal());
            $this->doi($sheet, "F{$row}", $article->getDoi());
            $this->text($sheet, "G{$row}", $this->workTypeOf($article));
            $sheet->setCellValue("H{$row}", $article->getCitesTypeA());
            $sheet->setCellValue("I{$row}", $article->getCitesTypeB());
            $sheet->setCellValue("J{$row}", $article->getCitesSelf());
            $sheet->setCellValue("K{$row}", $classified);
            $sheet->setCellValue("L{$row}", $article->getOpenalexCitedByCount());
            $this->number($sheet, "M{$row}", $article->getScopusCitedByCount());
            $this->number($sheet, "N{$row}", $article->getWosCitedByCount());
            $this->number($sheet, "O{$row}", $article->getZbmathCitedByCount());
            $this->number($sheet, "P{$row}", $article->getInspireCitedByCount());
            $this->text($sheet, "Q{$row}", $article->getOpenalexId());

            ++$row;
        }

        $this->finish($sheet, 'Q', $row - 1, [
            'A' => 5, 'B' => 64, 'C' => 34, 'D' => 7, 'E' => 30, 'F' => 30, 'G' => 18,
            'H' => 8, 'I' => 8, 'J' => 10, 'K' => 12,
            'L' => 10, 'M' => 10, 'N' => 14, 'O' => 10, 'P' => 12, 'Q' => 16,
        ]);

        // Titles and journals are long; wrapping keeps a row readable without
        // widening the column past the screen.
        $sheet->getStyle(sprintf('B2:C%d', max(2, $row - 1)))
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * Every citing work, one per row — the sheet the validation actually happens in.
     *
     * Flat rather than grouped under each article: a flat table is what filtering
     * and sorting work on, and "show me every autocita from Scopus" is the question
     * that gets asked. The cited article travels on each row so no row loses its
     * context when the reviewer sorts by something else.
     *
     * @param Article[] $articles
     */
    private function citationsSheet(Worksheet $sheet, array $articles): void
    {
        $sheet->setTitle('Citas');

        $this->header($sheet, 1, [
            '#', 'Artículo citado', 'DOI del artículo',
            'Título de la cita', 'Autores de la cita', 'Año', 'DOI de la cita',
            'Fuente', 'Clasificación', '¿Correcta?', 'Observaciones',
        ]);

        $row = 2;
        foreach ($articles as $index => $article) {
            foreach ($article->getCitingWorks() as $citation) {
                // The article's number, not the citation's: it points back to the row
                // on the Artículos sheet, which is what someone checking a total needs.
                $sheet->setCellValue("A{$row}", $index + 1);
                $this->text($sheet, "B{$row}", $this->plainTitle($article->getTitle()));
                $this->doi($sheet, "C{$row}", $article->getDoi());
                $this->text($sheet, "D{$row}", $this->plainTitle($citation->getTitle()));
                $this->text($sheet, "E{$row}", implode('; ', $citation->getAuthors()));
                $this->number($sheet, "F{$row}", $citation->getYear());
                $this->doi($sheet, "G{$row}", $citation->getDoi());
                $this->text($sheet, "H{$row}", self::SOURCES[$citation->getSource()] ?? $citation->getSource());
                $this->text($sheet, "I{$row}", $this->classificationOf($citation));

                ++$row;
            }
        }

        $last = $row - 1;

        $this->finish($sheet, 'K', $last, [
            'A' => 5, 'B' => 46, 'C' => 28, 'D' => 60, 'E' => 34, 'F' => 7,
            'G' => 28, 'H' => 14, 'I' => 20, 'J' => 12, 'K' => 40,
        ]);

        $sheet->getStyle(sprintf('B2:E%d', max(2, $last)))
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        // The reviewer's columns, marked off from the exported data so nobody has to
        // guess which side of the sheet is the machine's and which is theirs.
        $sheet->getStyle(sprintf('J1:K%d', max(2, $last)))->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF9E6');

        if ($last >= 2) {
            $sheet->setDataValidation(sprintf('J2:J%d', $last), $this->verdictDropdown());
        }
    }

    /**
     * How a work's type reads in the sheet. A preprint says where it is hosted,
     * because "preprint (arXiv)" is what a reviewer matches against the published
     * version sitting a few rows away. An unreported type is left blank rather than
     * guessed at: only OpenAlex tells us, and a row from Scopus or zbMATH alone
     * genuinely has no answer.
     */
    private function workTypeOf(Article $article): ?string
    {
        if ($article->isPreprint()) {
            $repository = $article->getRepository();

            return $repository !== null && $repository !== ''
                ? sprintf('Preprint (%s)', $repository)
                : 'Preprint';
        }

        return $article->getWorkType();
    }

    /**
     * The engine's flags and notes, verbatim.
     *
     * They travel with the figures because they are the reason a figure might be
     * wrong: a shortfall against OpenAlex's own count or an author code the engine
     * had to guess is exactly what the validation is looking for, and it would be
     * lost if the export carried only the numbers.
     */
    private function issuesSheet(Worksheet $sheet, AnalysisRun $run): void
    {
        $sheet->setTitle('Incidencias');

        $this->header($sheet, 1, ['Tipo', 'Detalle']);

        $row = 2;
        foreach ($run->getFlags() as $flag) {
            $this->text($sheet, "A{$row}", 'Alerta');
            $this->text($sheet, "B{$row}", $flag);
            ++$row;
        }
        foreach ($run->getNotes() as $note) {
            $this->text($sheet, "A{$row}", 'Nota');
            $this->text($sheet, "B{$row}", $note);
            ++$row;
        }

        if ($row === 2) {
            // Said outright: an empty sheet reads like the export failed rather than
            // like the run had nothing to report.
            $this->text($sheet, "A{$row}", '—');
            $this->text($sheet, "B{$row}", 'El motor no reportó alertas ni notas para este análisis.');
            ++$row;
        }

        $this->finish($sheet, 'B', $row - 1, ['A' => 12, 'B' => 110]);
        $sheet->getStyle(sprintf('B2:B%d', max(2, $row - 1)))
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /** Sí / No / Duda, as a dropdown — a free-text verdict column cannot be filtered on. */
    private function verdictDropdown(): DataValidation
    {
        $validation = new DataValidation();
        $validation
            ->setType(DataValidation::TYPE_LIST)
            // Informational, not blocking: the list is a convenience, and a reviewer
            // who needs to write something else should not be stopped by the sheet.
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Valor fuera de la lista')
            ->setError('Se esperaba Sí, No o Duda. Puedes escribir otra cosa si hace falta.')
            ->setFormula1('"Sí,No,Duda"');

        return $validation;
    }

    /** @param list<string> $labels */
    private function header(Worksheet $sheet, int $row, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $sheet->setCellValueExplicit([$index + 1, $row], $label, DataType::TYPE_STRING);
        }

        $range = sprintf('A%d:%s%d', $row, $this->columnName(count($labels)), $row);
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * The three things that make a long sheet workable: a header that stays put
     * while scrolling, a filter on every column, and widths that fit the content.
     *
     * @param array<string,int> $widths column letter => width
     */
    private function finish(Worksheet $sheet, string $lastColumn, int $lastRow, array $widths): void
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter(sprintf('A1:%s%d', $lastColumn, max(2, $lastRow)));

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * Text, written as text explicitly.
     *
     * Excel reads a leading "=" as a formula and a leading "-" as arithmetic, and
     * paper titles do start with both. Left to the default type detection, such a
     * title lands in the sheet as #NAME? — the citation silently disappears from a
     * list someone is checking for completeness.
     */
    private function text(Worksheet $sheet, string $cell, ?string $value): void
    {
        $value = trim((string) $value);
        $sheet->setCellValueExplicit($cell, $value !== '' ? $value : '—', DataType::TYPE_STRING);
    }

    /** A missing count is an em dash, not a zero: the two mean different things here. */
    private function number(Worksheet $sheet, string $cell, ?int $value): void
    {
        if ($value === null) {
            $sheet->setCellValueExplicit($cell, '—', DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    /** The DOI as text, hyperlinked to its resolver when it is one. */
    private function doi(Worksheet $sheet, string $cell, ?string $doi): void
    {
        // Shown bare even where the row still holds the resolved URL. Rows written
        // before Article::normalizeDoi() kept the https://doi.org/ form, and most
        // runs on file predate it — left alone, the column mixes both shapes and
        // stops being sortable or comparable by eye, which is all it is there for.
        $this->text($sheet, $cell, Article::normalizeDoi($doi));

        $url = $this->links->doiUrl($doi);

        if ($url === null) {
            return;
        }

        $sheet->getCell($cell)->getHyperlink()->setUrl($url);
        $sheet->getStyle($cell)->getFont()->setUnderline(true)->getColor()->setARGB(self::LINK_COLOR);
    }

    /**
     * A title as a spreadsheet can show it.
     *
     * OpenAlex returns inline markup ("<i>P</i>-points") and HTML entities. A
     * browser hides both; a cell prints them raw, and "Malykhin&rsquo;s problem" is
     * not a title anyone can match against a paper in front of them.
     *
     * Tags are stripped before entities are decoded, never after: an escaped
     * "&lt;i&gt;" was literal text in the record, and decoding first would turn it
     * into a tag and then delete it.
     */
    private function plainTitle(string $title): string
    {
        return html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function classificationOf(CitingWork $citation): string
    {
        $code = $citation->getClassification();

        // Nulls are not padding: an unclassified citation counts towards no total,
        // so it has to be visible as its own case rather than blend into Tipo A.
        if ($code === null) {
            return 'Sin clasificar';
        }

        return self::CLASSIFICATIONS[$code] ?? 'Sin clasificar';
    }

    private function moment(?\DateTimeInterface $moment): string
    {
        return $moment?->format('Y-m-d H:i') ?? '—';
    }

    /** 1 → A, 27 → AA. */
    private function columnName(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
