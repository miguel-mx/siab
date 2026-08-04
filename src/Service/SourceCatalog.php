<?php

namespace App\Service;

use App\Entity\Researcher;
use App\Enum\AnalysisSource;

/**
 * Which sources a run may use, which are offered by default, and how a selection
 * survives as text on the run.
 *
 * Availability is not a preference: Scopus and Web of Science are unusable without
 * an API key, so they are shown as unavailable rather than as an option that would
 * silently do nothing. The defaults follow the researcher — INSPIRE is only
 * pre-selected for someone who has an INSPIRE recid, because for a mathematician
 * it is a round trip that returns nothing.
 */
final class SourceCatalog
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** Whether this deployment can query the source at all. */
    public function isAvailable(AnalysisSource $source): bool
    {
        $key = $source->apiKeySetting();

        return $key === null || trim($this->settings->get($key)) !== '';
    }

    /**
     * Why a source cannot be chosen, or null when it can.
     */
    public function unavailableReason(AnalysisSource $source): ?string
    {
        return $this->isAvailable($source)
            ? null
            : sprintf('Sin clave de API configurada (Administración → Configuración del motor).');
    }

    /**
     * What to tick when the form is first drawn: everything available, except
     * INSPIRE for a researcher with no recid on file.
     *
     * @return list<string>
     */
    public function defaults(?Researcher $researcher = null): array
    {
        $chosen = [];

        foreach (AnalysisSource::optional() as $source) {
            if (!$this->isAvailable($source)) {
                continue;
            }

            if ($source === AnalysisSource::INSPIRE && ($researcher === null || !$researcher->getInspireRecid())) {
                continue;
            }

            $chosen[] = $source->value;
        }

        return $chosen;
    }

    /**
     * Clean a submitted selection: drop anything unknown or unavailable, and put
     * OpenAlex back whatever was posted — a run without it is not an analysis.
     *
     * @param array<mixed> $submitted
     *
     * @return list<string>
     */
    public function sanitize(array $submitted): array
    {
        $chosen = [AnalysisSource::OPENALEX->value];

        foreach ($submitted as $value) {
            $source = AnalysisSource::tryFrom((string) $value);

            if ($source !== null && $source->isOptional() && $this->isAvailable($source)) {
                $chosen[] = $source->value;
            }
        }

        return array_values(array_unique($chosen));
    }
}
