<?php

namespace App\Entity;

use App\Repository\ResearcherRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A researcher in the curated CCM-UNAM roster (~30). Holds the cross-database
 * identifiers used to drive an analysis. IDs are stored in their *bare* canonical
 * form (e.g. "A5031242743", "0009-0001-6922-2192") — the engine returns them as
 * full URLs, so use the normalize* helpers on the way in.
 */
#[ORM\Entity(repositoryClass: ResearcherRepository::class)]
#[ORM\Table(name: 'researcher')]
#[ORM\UniqueConstraint(name: 'uniq_researcher_orcid', columns: ['orcid'])]
#[ORM\UniqueConstraint(name: 'uniq_researcher_openalex', columns: ['openalex_id'])]
class Researcher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $displayName;

    /** URL identity, e.g. "michael-hrusak". Assigned by SlugGenerator on creation. */
    #[ORM\Column(length: 140, unique: true)]
    private ?string $slug = null;

    /** Research area, e.g. "Topología · Teoría de conjuntos". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $field = null;

    #[ORM\Column(length: 19, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $openalexId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $scopusId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $zbmathCode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $inspireRecid = null;

    /** Works count as last reported by OpenAlex (informational). */
    #[ORM\Column(nullable: true)]
    private ?int $worksCount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AnalysisRun> */
    #[ORM\OneToMany(targetEntity: AnalysisRun::class, mappedBy: 'researcher')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $analyses;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->analyses = new ArrayCollection();
    }

    // ── Normalization: strip the URL prefixes the engine returns ──────────────
    public static function normalizeOrcid(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Uppercased for the one non-digit ORCID allows: the X check character.
        return mb_strtoupper((string) preg_replace('#^https?://orcid\.org/#i', '', trim($value)));
    }

    /**
     * Shape plus the ISO 7064 MOD 11-2 check character every ORCID carries.
     *
     * Worth checking rather than trusting: a mistyped ORCID is refused by nothing
     * downstream. It resolves to a different person, or to nobody, and the figures
     * come back wrong with no error anywhere to notice.
     */
    public static function isValidOrcid(string $orcid): bool
    {
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
            return false;
        }

        $digits = str_replace('-', '', $orcid);
        $total = 0;

        for ($i = 0; $i < 15; ++$i) {
            $total = ($total + (int) $digits[$i]) * 2;
        }

        $expected = (12 - $total % 11) % 11;

        return $digits[15] === ($expected === 10 ? 'X' : (string) $expected);
    }

    public static function normalizeOpenalexId(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('#^https?://openalex\.org/#i', '', trim($value));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function setField(?string $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function getOrcid(): ?string
    {
        return $this->orcid;
    }

    public function setOrcid(?string $orcid): static
    {
        $this->orcid = self::normalizeOrcid($orcid);

        return $this;
    }

    public function getOpenalexId(): ?string
    {
        return $this->openalexId;
    }

    public function setOpenalexId(?string $openalexId): static
    {
        $this->openalexId = self::normalizeOpenalexId($openalexId);

        return $this;
    }

    public function getScopusId(): ?string
    {
        return $this->scopusId;
    }

    public function setScopusId(?string $scopusId): static
    {
        $this->scopusId = $scopusId;

        return $this;
    }

    public function getZbmathCode(): ?string
    {
        return $this->zbmathCode;
    }

    public function setZbmathCode(?string $zbmathCode): static
    {
        $this->zbmathCode = $zbmathCode;

        return $this;
    }

    public function getInspireRecid(): ?string
    {
        return $this->inspireRecid;
    }

    public function setInspireRecid(?string $inspireRecid): static
    {
        $this->inspireRecid = $inspireRecid;

        return $this;
    }

    public function getWorksCount(): ?int
    {
        return $this->worksCount;
    }

    public function setWorksCount(?int $worksCount): static
    {
        $this->worksCount = $worksCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * The identifier to hand the engine's /analyze, most authoritative first.
     *
     * ORCID leads: the researcher owns it, it survives OpenAlex merging or
     * re-issuing author records, and OpenAlex resolves it directly
     * (/authors/orcid:…). The OpenAlex id is the fallback for the many
     * mathematicians who have no ORCID on record.
     *
     * The Scopus AU-ID deliberately does not appear here. The engine's resolver
     * understands two things — an OpenAlex id and an ORCID — and anything else is
     * treated as a *name* to search for, so a numeric AU-ID would come back as no
     * match and /analyze would answer 422. It travels separately, as
     * scopus_author_id, for the Scopus source itself.
     */
    public function preferredEngineQuery(): ?string
    {
        return $this->orcid ?? $this->openalexId;
    }

    /** @return Collection<int, AnalysisRun> */
    public function getAnalyses(): Collection
    {
        return $this->analyses;
    }

    public function addAnalysis(AnalysisRun $analysis): static
    {
        if (!$this->analyses->contains($analysis)) {
            $this->analyses->add($analysis);
            $analysis->setResearcher($this);
        }

        return $this;
    }

    public function removeAnalysis(AnalysisRun $analysis): static
    {
        $this->analyses->removeElement($analysis);

        return $this;
    }
}
