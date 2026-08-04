<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the "Nuevo análisis" form collects. A free-text identifier because the
 * engine's /resolve accepts all three forms — ORCID, OpenAlex ID or a name (which
 * then goes through disambiguation).
 */
final class NewAnalysisInput
{
    #[Assert\NotBlank(message: 'Escribe un ORCID, un ID de OpenAlex o un nombre.')]
    #[Assert\Length(min: 3, max: 180, minMessage: 'Escribe al menos {{ limit }} caracteres.')]
    public ?string $query = null;

    /** Reports need Ollama, so the form keeps this opt-in. */
    public bool $wantReport = false;

    #[Assert\Choice(choices: ['es', 'en'])]
    public string $reportLanguage = 'es';

    /**
     * Optional bibliographic sources to draw on, by AnalysisSource value. Not
     * validated here: SourceCatalog::sanitize() is the authority, because what is
     * usable depends on which API keys the deployment has.
     *
     * @var list<string>
     */
    public array $sources = [];
}
