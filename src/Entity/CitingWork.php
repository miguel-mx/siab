<?php

namespace App\Entity;

use App\Repository\CitingWorkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single work that cites one of the researcher's articles — the actual citing
 * reference, not just a count. `classification` (A/B/self) is stored per work so
 * the UI can filter; it is computed deterministically by the engine.
 */
#[ORM\Entity(repositoryClass: CitingWorkRepository::class)]
#[ORM\Table(name: 'citing_work')]
#[ORM\Index(name: 'idx_citing_article', columns: ['article_id'])]
class CitingWork
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'citingWorks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $openalexId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $title;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    /** @var list<string> Citing authors' display names. */
    #[ORM\Column(type: Types::JSON)]
    private array $authors = [];

    /** Which source produced this record: openalex | scopus | zbmath | inspire. */
    #[ORM\Column(length: 32, options: ['default' => 'openalex'])]
    private string $source = 'openalex';

    /** 'A' (external), 'B' (an author of the cited article), or 'self'. Nullable until classified. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $classification = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;

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

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): static
    {
        // Same canonical form as the articles — see Article::normalizeDoi().
        $this->doi = Article::normalizeDoi($doi);

        return $this;
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

    /** @return list<string> */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    /** @param list<string> $authors */
    public function setAuthors(array $authors): static
    {
        $this->authors = $authors;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getClassification(): ?string
    {
        return $this->classification;
    }

    public function setClassification(?string $classification): static
    {
        $this->classification = $classification;

        return $this;
    }
}
