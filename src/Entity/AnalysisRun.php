<?php

namespace App\Entity;

use App\Enum\AnalysisSource;
use App\Enum\RunStatus;
use App\Repository\AnalysisRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One execution of the citation pipeline for a researcher. Article/CitingWork rows
 * hold the queryable canonical data; `rawSnapshot` keeps the engine's exact
 * AnalysisResult JSON for audit/traceability (every number stays traceable to a
 * tool output). Totals are denormalized so the dashboard never unpacks the graph.
 */
#[ORM\Entity(repositoryClass: AnalysisRunRepository::class)]
#[ORM\Table(name: 'analysis_run')]
#[ORM\Index(name: 'idx_run_status', columns: ['status'])]
#[ORM\Index(name: 'idx_run_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_run_discarded', columns: ['discarded_at'])]
class AnalysisRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** URL identity, e.g. "michael-hrusak-20260729-2354" — who and when, at a glance. */
    #[ORM\Column(length: 160, unique: true)]
    private ?string $slug = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'analyses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Researcher $researcher;

    /** Who launched the run. Nullable until auth is wired; then required. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::QUEUED;

    /**
     * Archived: hidden from the history and left out of every aggregate, but still
     * on file so a report published from these figures stays reproducible.
     * See App\Doctrine\ArchivedRunFilter, which is what actually enforces it.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $discardedAt = null;

    /** Comma-separated data sources requested, e.g. "openalex" or "openalex,scopus". */
    #[ORM\Column(length: 120)]
    private string $sources = 'openalex';

    #[ORM\Column(length: 2)]
    private string $reportLanguage = 'es';

    /** The engine's run_timestamp (UTC, "YYYYmm ...Z") once the run completes. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $runTimestamp = null;

    // ── Denormalized totals (fast dashboard reads) ────────────────────────────
    #[ORM\Column(options: ['default' => 0])]
    private int $totalArticles = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTypeA = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTypeB = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalSelf = 0;

    /**
     * Findings the engine wants a person to act on — a rejected API key, a
     * shortfall against OpenAlex's own count, an author code it had to guess.
     * Shown on the run itself; they qualify its figures without changing its status.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $flags = [];

    /**
     * Context with nothing to act on: which source contributed what, why an
     * unconfigured source was skipped. Recorded so a partial run is never read as
     * a complete one — but never a reason to mark the run for review.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notes = null;

    /** Exact AnalysisResult JSON from the engine, for audit/re-derivation. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawSnapshot = null;

    /**
     * Narrative report as the model wrote it — Markdown, converted to HTML at
     * display time so this column stays exactly what was generated.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $report = null;

    /**
     * Set while a report is queued/being written, cleared when it finishes either
     * way. Generation is a separate job from the analysis (it can be asked for long
     * afterwards), so it needs its own in-flight marker.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reportRequestedAt = null;

    /** Why the last report attempt failed. Kept apart from the analysis's own error. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reportError = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'analysisRun', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $articles;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->articles = new ArrayCollection();
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

    public function getResearcher(): Researcher
    {
        return $this->researcher;
    }

    public function setResearcher(Researcher $researcher): static
    {
        $this->researcher = $researcher;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function setStatus(RunStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSources(): string
    {
        return $this->sources;
    }

    /**
     * The sources this run drew on, named for display.
     *
     * @return list<string>
     */
    public function getSourceLabels(): array
    {
        return array_map(
            static fn (AnalysisSource $s): string => $s->label(),
            AnalysisSource::parseList($this->sources),
        );
    }

    /** @return list<string> */
    public function getNotes(): array
    {
        return $this->notes ?? [];
    }

    /** @param list<string> $notes */
    public function setNotes(array $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function setSources(string $sources): static
    {
        $this->sources = $sources;

        return $this;
    }

    public function getReportLanguage(): string
    {
        return $this->reportLanguage;
    }

    public function setReportLanguage(string $reportLanguage): static
    {
        $this->reportLanguage = $reportLanguage;

        return $this;
    }

    public function getRunTimestamp(): ?string
    {
        return $this->runTimestamp;
    }

    public function setRunTimestamp(?string $runTimestamp): static
    {
        $this->runTimestamp = $runTimestamp;

        return $this;
    }

    public function getTotalArticles(): int
    {
        return $this->totalArticles;
    }

    public function setTotalArticles(int $totalArticles): static
    {
        $this->totalArticles = $totalArticles;

        return $this;
    }

    public function getTotalTypeA(): int
    {
        return $this->totalTypeA;
    }

    public function setTotalTypeA(int $totalTypeA): static
    {
        $this->totalTypeA = $totalTypeA;

        return $this;
    }

    public function getTotalTypeB(): int
    {
        return $this->totalTypeB;
    }

    public function setTotalTypeB(int $totalTypeB): static
    {
        $this->totalTypeB = $totalTypeB;

        return $this;
    }

    public function getTotalSelf(): int
    {
        return $this->totalSelf;
    }

    public function setTotalSelf(int $totalSelf): static
    {
        $this->totalSelf = $totalSelf;

        return $this;
    }

    /** Total citations counted (A + B + self). */
    public function getTotalCitations(): int
    {
        return $this->totalTypeA + $this->totalTypeB + $this->totalSelf;
    }

    /** @return list<string> */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /** @param list<string> $flags */
    public function setFlags(array $flags): static
    {
        $this->flags = $flags;

        return $this;
    }

    public function getRawSnapshot(): ?array
    {
        return $this->rawSnapshot;
    }

    public function setRawSnapshot(?array $rawSnapshot): static
    {
        $this->rawSnapshot = $rawSnapshot;

        return $this;
    }

    public function getReport(): ?string
    {
        return $this->report;
    }

    public function setReport(?string $report): static
    {
        $this->report = $report;

        return $this;
    }

    public function getReportRequestedAt(): ?\DateTimeImmutable
    {
        return $this->reportRequestedAt;
    }

    public function setReportRequestedAt(?\DateTimeImmutable $reportRequestedAt): static
    {
        $this->reportRequestedAt = $reportRequestedAt;

        return $this;
    }

    public function getReportError(): ?string
    {
        return $this->reportError;
    }

    public function setReportError(?string $reportError): static
    {
        $this->reportError = $reportError;

        return $this;
    }

    public function getDiscardedAt(): ?\DateTimeImmutable
    {
        return $this->discardedAt;
    }

    public function setDiscardedAt(?\DateTimeImmutable $discardedAt): static
    {
        $this->discardedAt = $discardedAt;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->discardedAt !== null;
    }

    /**
     * Archiving is for runs that produced figures: hiding one is a judgement about
     * results, and the results are what stay reproducible. A run without figures has
     * nothing to preserve — that one gets deleted instead.
     */
    public function isArchivable(): bool
    {
        return !$this->isArchived() && $this->status->hasFigures();
    }

    /**
     * Deleting is for the junk a working system leaves behind — failed attempts and
     * cancelled ones. Nothing aggregates them, so nothing changes when they go.
     * A run that produced figures is never deletable; archive it.
     */
    public function isDeletable(): bool
    {
        return $this->status->isTerminal() && !$this->status->hasFigures();
    }

    /** A report is being written right now. */
    public function isReportPending(): bool
    {
        return $this->reportRequestedAt !== null;
    }

    /** Minutes after which a RUNNING analysis has almost certainly lost its worker. */
    private const STALL_MINUTES = 30;

    /**
     * Running far longer than the pipeline ever takes. Not a status — nothing can
     * observe the worker's death from here — but enough to stop the page claiming
     * progress that is not happening, and to suggest cancelling.
     */
    public function isStalled(): bool
    {
        if ($this->status !== RunStatus::RUNNING || $this->startedAt === null) {
            return false;
        }

        return $this->startedAt < new \DateTimeImmutable(sprintf('-%d minutes', self::STALL_MINUTES));
    }

    /**
     * Whether a report can be asked for: the run produced figures, its engine
     * snapshot is still on file (that is what the report is written from — no new
     * OpenAlex calls), and no attempt is already in flight.
     */
    public function canGenerateReport(): bool
    {
        return $this->status->hasFigures()
            && $this->rawSnapshot !== null
            && !$this->isReportPending();
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setAnalysisRun($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            if ($article->getAnalysisRun() === $this) {
                $article->setAnalysisRun(null);
            }
        }

        return $this;
    }
}
