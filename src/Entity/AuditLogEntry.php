<?php

namespace App\Entity;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recorded change, append-only. Nothing in the application updates or deletes
 * these rows — a log you can edit answers no question worth asking.
 *
 * The subject is stored as a type/id pair plus a *label captured at the time*,
 * rather than as a foreign key. That is the whole point for deletions: after an
 * analysis is deleted there is no row left to join to, and "Análisis #9
 * (Michael Hrušák)" is exactly what someone reading the log needs to see. The
 * actor keeps a real relation as well, since accounts are never deleted.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_subject', columns: ['subject_type', 'subject_id'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, enumType: AuditAction::class)]
    private AuditAction $action;

    /** Null for anything the system did on its own (the stalled-run sweep). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Who they were when they did it, in case the account changes name later. */
    #[ORM\Column(length: 190)]
    private string $actorLabel;

    #[ORM\Column(length: 20)]
    private string $subjectType;

    /** Kept even when the row it pointed at is gone. */
    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    #[ORM\Column(length: 255)]
    private string $subjectLabel;

    /** @var array<string,mixed>|null Extra context, e.g. the reason a run was closed. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string,mixed>|null $details */
    public function __construct(
        AuditAction $action,
        ?User $actor,
        string $actorLabel,
        string $subjectLabel,
        ?int $subjectId = null,
        ?array $details = null,
    ) {
        $this->action = $action;
        $this->actor = $actor;
        $this->actorLabel = $actorLabel;
        $this->subjectType = $action->subjectType();
        $this->subjectId = $subjectId;
        $this->subjectLabel = $subjectLabel;
        $this->details = $details === [] ? null : $details;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    /** @return array<string,mixed>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
