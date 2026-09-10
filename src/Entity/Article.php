<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of a researcher's works within a run, with its per-source citation counts
 * and the A/B/self classification totals. Mirrors the engine's `Article` schema.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'article')]
#[ORM\Index(name: 'idx_article_run', columns: ['analysis_run_id'])]
// The run's article list can be read with preprints hidden, which filters on
// work_type within one run — so the index carries both columns, not just the run.
#[ORM\Index(name: 'idx_article_run_type', columns: ['analysis_run_id', 'work_type'])]
class Article
{
    /** The engine's value for a preprint; the filter and the entity must agree on it. */
    public const TYPE_PREPRINT = 'preprint';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AnalysisRun::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AnalysisRun $analysisRun = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $openalexId = null;

    // ── Per-source record identifiers ─────────────────────────────────────────
    // Kept so a reviewer can follow a figure back to the source record, and so a
    // re-run does not have to resolve the same article again. Null simply means
    // that source had no record for this work.
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $scopusId = null;

    /** Full EID, e.g. 2-s2.0-84912345678 — the form Scopus links and refeid() use. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $scopusEid = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $wosUid = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $zbmathId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $inspireRecid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $title;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    /**
     * TEXT, not VARCHAR(255): for a book chapter zbMATH returns the whole host
     * citation here ("Babinkostova, L. (ed.) et al., Set theory and its
     * applications. Annual Boise extravaganza…"), which runs past 255 characters.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $journal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authors = null;

    /**
     * The engine's normalised work type — 'article', 'preprint', 'book-chapter',
     * 'review'. Null for records that reached us from a source which reports none
     * (everything but OpenAlex), and that null means *unclassified*: such a work is
     * never hidden by the preprint filter, because we were never told what it is.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $workType = null;

    /** Where a preprint is hosted — arXiv, bioRxiv, HAL. Null for everything else. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $repository = null;

    // ── Per-source citation counts ────────────────────────────────────────────
    #[ORM\Column(options: ['default' => 0])]
    private int $openalexCitedByCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $scopusCitedByCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $wosCitedByCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $zbmathCitedByCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $inspireCitedByCount = null;

    // ── Classification totals ─────────────────────────────────────────────────
    #[ORM\Column(options: ['default' => 0])]
    private int $citesTypeA = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $citesTypeB = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $citesSelf = 0;

    /** @var Collection<int, CitingWork> */
    #[ORM\OneToMany(targetEntity: CitingWork::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
    // Newest first, the way a citation list is read. Applied on the association so
    // every load is ordered — the list arrives from the on-demand fragment, and an
    // unordered one comes back in whatever order the rows happen to sit in.
    #[ORM\OrderBy(['year' => 'DESC', 'title' => 'ASC'])]
    private Collection $citingWorks;

    public function __construct()
    {
        $this->citingWorks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnalysisRun(): ?AnalysisRun
    {
        return $this->analysisRun;
    }

    public function setAnalysisRun(?AnalysisRun $analysisRun): static
    {
        $this->analysisRun = $analysisRun;

        return $this;
    }

    public function getOpenalexId(): ?string
    {
        return $this->openalexId;
    }

    public function setOpenalexId(?string $openalexId): static
    {
        $this->openalexId = $openalexId;

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

    public function getScopusEid(): ?string
    {
        return $this->scopusEid;
    }

    public function setScopusEid(?string $scopusEid): static
    {
        $this->scopusEid = $scopusEid;

        return $this;
    }

    public function getWosUid(): ?string
    {
        return $this->wosUid;
    }

    public function setWosUid(?string $wosUid): static
    {
        $this->wosUid = $wosUid;

        return $this;
    }

    public function getZbmathId(): ?string
    {
        return $this->zbmathId;
    }

    public function setZbmathId(?string $zbmathId): static
    {
        $this->zbmathId = $zbmathId;

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

    /**
     * Which sources hold a record for this work — drives the source chips on the
     * run detail page, and answers "did Scopus even know about this paper?".
     *
     * @return list<string>
     */
    public function getSourcesPresent(): array
    {
        $present = [];
        foreach ([
            'openalex' => $this->openalexId,
            'scopus' => $this->scopusEid ?? $this->scopusId,
            'wos' => $this->wosUid,
            'zbmath' => $this->zbmathId,
            'inspire' => $this->inspireRecid,
        ] as $source => $identifier) {
            if ($identifier !== null && $identifier !== '') {
                $present[] = $source;
            }
        }

        return $present;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): static
    {
        $this->doi = self::normalizeDoi($doi);

        return $this;
    }

    /**
     * Sources disagree on the shape: OpenAlex hands back a resolved
     * "https://doi.org/10.1016/…", zbMATH and Scopus the bare "10.1016/…".
     * Stored resolved, the templates' https://doi.org/ prefix produced links like
     * https://doi.org/https://doi.org/10.1016/… — so the bare form is canonical
     * here, and the doi_url Twig filter puts the resolver back on for display.
     * Rows written before this normalisation still hold the URL form, which is
     * why that filter accepts either.
     */
    public static function normalizeDoi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:)#i', '', trim($value));
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getJournal(): ?string
    {
        return $this->journal;
    }

    public function setJournal(?string $journal): static
    {
        $this->journal = $journal;

        return $this;
    }

    public function getAuthors(): ?string
    {
        return $this->authors;
    }

    public function setAuthors(?string $authors): static
    {
        $this->authors = $authors;

        return $this;
    }

    public function getWorkType(): ?string
    {
        return $this->workType;
    }

    public function setWorkType(?string $workType): static
    {
        $this->workType = $workType;

        return $this;
    }

    public function getRepository(): ?string
    {
        return $this->repository;
    }

    public function setRepository(?string $repository): static
    {
        $this->repository = $repository;

        return $this;
    }

    /**
     * Deliberately an exact match on the one type the engine promotes records to,
     * and not "anything that is not an article": a book chapter is not a preprint,
     * and neither is a work whose type nobody reported.
     */
    public function isPreprint(): bool
    {
        return $this->workType === self::TYPE_PREPRINT;
    }

    public function getOpenalexCitedByCount(): int
    {
        return $this->openalexCitedByCount;
    }

    public function setOpenalexCitedByCount(int $openalexCitedByCount): static
    {
        $this->openalexCitedByCount = $openalexCitedByCount;

        return $this;
    }

    public function getScopusCitedByCount(): ?int
    {
        return $this->scopusCitedByCount;
    }

    public function setScopusCitedByCount(?int $scopusCitedByCount): static
    {
        $this->scopusCitedByCount = $scopusCitedByCount;

        return $this;
    }

    public function getWosCitedByCount(): ?int
    {
        return $this->wosCitedByCount;
    }

    public function setWosCitedByCount(?int $wosCitedByCount): static
    {
        $this->wosCitedByCount = $wosCitedByCount;

        return $this;
    }

    public function getZbmathCitedByCount(): ?int
    {
        return $this->zbmathCitedByCount;
    }

    public function setZbmathCitedByCount(?int $zbmathCitedByCount): static
    {
        $this->zbmathCitedByCount = $zbmathCitedByCount;

        return $this;
    }

    public function getInspireCitedByCount(): ?int
    {
        return $this->inspireCitedByCount;
    }

    public function setInspireCitedByCount(?int $inspireCitedByCount): static
    {
        $this->inspireCitedByCount = $inspireCitedByCount;

        return $this;
    }

    public function getCitesTypeA(): int
    {
        return $this->citesTypeA;
    }

    public function setCitesTypeA(int $citesTypeA): static
    {
        $this->citesTypeA = $citesTypeA;

        return $this;
    }

    public function getCitesTypeB(): int
    {
        return $this->citesTypeB;
    }

    public function setCitesTypeB(int $citesTypeB): static
    {
        $this->citesTypeB = $citesTypeB;

        return $this;
    }

    public function getCitesSelf(): int
    {
        return $this->citesSelf;
    }

    public function setCitesSelf(int $citesSelf): static
    {
        $this->citesSelf = $citesSelf;

        return $this;
    }

    /** @return Collection<int, CitingWork> */
    public function getCitingWorks(): Collection
    {
        return $this->citingWorks;
    }

    public function addCitingWork(CitingWork $citingWork): static
    {
        if (!$this->citingWorks->contains($citingWork)) {
            $this->citingWorks->add($citingWork);
            $citingWork->setArticle($this);
        }

        return $this;
    }

    public function removeCitingWork(CitingWork $citingWork): static
    {
        if ($this->citingWorks->removeElement($citingWork)) {
            if ($citingWork->getArticle() === $this) {
                $citingWork->setArticle(null);
            }
        }

        return $this;
    }
}
