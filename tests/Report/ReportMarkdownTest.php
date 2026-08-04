<?php

namespace App\Tests\Report;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The narrative report is stored as the model's raw Markdown and converted at
 * display time. The model writes prose around OpenAlex titles, so its output is
 * not trusted: these tests pin the hardening in config/packages/twig_extra.yaml,
 * which is the only thing standing between a crafted paper title and markup on
 * the page.
 */
final class ReportMarkdownTest extends KernelTestCase
{
    private function render(string $markdown): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        return $twig->createTemplate('{{ md|markdown_to_html }}')->render(['md' => $markdown]);
    }

    public function testFormatsTheMarkdownTheModelActuallyWrites(): void
    {
        $html = $this->render(<<<'MD'
            ### 2. RESUMEN GENERAL

            El perfil cuenta con **41 artículos** y *225 citas*.

            *   **Citas Tipo A:** 113 (50.2%)
            *   **Autocitas:** 68 (30.2%)
            MD);

        $this->assertStringContainsString('<h3>', $html);
        $this->assertStringContainsString('<strong>41 artículos</strong>', $html);
        $this->assertStringContainsString('<em>225 citas</em>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('113 (50.2%)', $html);
    }

    public function testStripsRawHtml(): void
    {
        $html = $this->render('Antes <script>alert(1)</script> y <img src=x onerror=alert(1)> después.');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('Antes', $html);
        $this->assertStringContainsString('después', $html);
    }

    public function testDropsUnsafeLinkSchemes(): void
    {
        $html = $this->render('[pulsa](javascript:alert(1)) y [datos](data:text/html;base64,PHN2Zz4=)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
    }

    public function testKeepsLegitimateDoiLinks(): void
    {
        $html = $this->render('Ver [el artículo](https://doi.org/10.1016/j.topol.2018.01.001).');

        $this->assertStringContainsString('href="https://doi.org/10.1016/j.topol.2018.01.001"', $html);
    }
}
