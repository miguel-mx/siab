<?php

namespace App\Tests\Engine;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Enum\AuditAction;
use PHPUnit\Framework\TestCase;

/**
 * The properties that make the trail worth keeping: it survives the thing it
 * describes, it never invents an actor, and it never records a secret.
 */
final class AuditLogEntryTest extends TestCase
{
    public function testAnEntryKeepsWhatItNeedsAfterTheSubjectIsGone(): void
    {
        $entry = new AuditLogEntry(
            action: AuditAction::RUN_DELETED,
            actor: null,
            actorLabel: 'admin@matmor.unam.mx',
            subjectLabel: 'Análisis #9 (Michael Hrušák)',
            subjectId: 9,
            details: ['estado' => 'failed'],
        );

        // No relation to follow once the run is deleted — the label is the record.
        self::assertSame('Análisis #9 (Michael Hrušák)', $entry->getSubjectLabel());
        self::assertSame(9, $entry->getSubjectId());
        self::assertSame(['estado' => 'failed'], $entry->getDetails());
    }

    public function testTheSubjectTypeFollowsFromTheActionRatherThanTheCaller(): void
    {
        $run = new AuditLogEntry(AuditAction::RUN_ARCHIVED, null, 'x', 'Análisis #1', 1);
        $user = new AuditLogEntry(AuditAction::USER_PROMOTED, null, 'x', 'a@b.c', 1);

        self::assertSame('run', $run->getSubjectType());
        self::assertSame('user', $user->getSubjectType());
    }

    public function testEmptyDetailsAreStoredAsNothingRatherThanAnEmptyObject(): void
    {
        $entry = new AuditLogEntry(AuditAction::RUN_RESTORED, null, 'x', 'Análisis #1', 1, []);

        self::assertNull($entry->getDetails());
    }

    public function testASystemActionIsRecordedAsSuchNotAsAnUnknownPerson(): void
    {
        $entry = new AuditLogEntry(AuditAction::RUN_REAPED, null, 'sistema', 'Análisis #9', 9);

        self::assertNull($entry->getActor());
        self::assertSame('sistema', $entry->getActorLabel());
    }

    public function testAPersonsActionKeepsBothTheRelationAndTheNameAtTheTime(): void
    {
        $actor = (new User())->setEmail('admin@matmor.unam.mx')->setDisplayName('Admin CCM');
        $entry = new AuditLogEntry(AuditAction::USER_DEACTIVATED, $actor, 'Admin CCM', 'otro@matmor.unam.mx', 4);

        self::assertSame($actor, $entry->getActor());
        self::assertSame('Admin CCM', $entry->getActorLabel());
    }

    /** Destructive or privilege-granting entries are the ones worth spotting. */
    public function testSevereActionsAreTheDestructiveAndPrivilegeGrantingOnes(): void
    {
        self::assertTrue(AuditAction::RUN_DELETED->isSevere());
        self::assertTrue(AuditAction::USER_PROMOTED->isSevere());
        self::assertTrue(AuditAction::USER_DEACTIVATED->isSevere());

        self::assertFalse(AuditAction::RUN_ARCHIVED->isSevere());
        self::assertFalse(AuditAction::USER_CREATED->isSevere());
    }

    /** Every action must render — a log with a blank column explains nothing. */
    public function testEveryActionHasALabel(): void
    {
        foreach (AuditAction::cases() as $action) {
            self::assertNotSame('', $action->label(), $action->value);
        }
    }
}
