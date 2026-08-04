<?php

namespace App\Enum;

/**
 * What an audit entry records. The value is stored, so these strings are part of
 * the data: rename one and the history stops making sense.
 *
 * Two families, deliberately in one log. Both answer the same question — "who
 * changed what, and when" — and the answers are most useful side by side: an
 * account promoted at 11:04 and an analysis deleted at 11:06 is a story.
 */
enum AuditAction: string
{
    case RUN_CANCELED = 'run.canceled';
    case RUN_REAPED = 'run.reaped';
    case RUN_ARCHIVED = 'run.archived';
    case RUN_RESTORED = 'run.restored';
    case RUN_DELETED = 'run.deleted';

    case USER_CREATED = 'user.created';
    case USER_PROMOTED = 'user.promoted';
    case USER_DEMOTED = 'user.demoted';
    case USER_ACTIVATED = 'user.activated';
    case USER_DEACTIVATED = 'user.deactivated';
    case USER_PASSWORD_RESET = 'user.password_reset';

    public function label(): string
    {
        return match ($this) {
            self::RUN_CANCELED => 'Análisis cancelado',
            self::RUN_REAPED => 'Análisis cerrado sin worker',
            self::RUN_ARCHIVED => 'Análisis archivado',
            self::RUN_RESTORED => 'Análisis restaurado',
            self::RUN_DELETED => 'Análisis eliminado',
            self::USER_CREATED => 'Cuenta creada',
            self::USER_PROMOTED => 'Permisos de administrador concedidos',
            self::USER_DEMOTED => 'Permisos de administrador retirados',
            self::USER_ACTIVATED => 'Cuenta reactivada',
            self::USER_DEACTIVATED => 'Cuenta desactivada',
            self::USER_PASSWORD_RESET => 'Contraseña restablecida',
        };
    }

    /** Which screen the entry belongs to, for the log's filter. */
    public function subjectType(): string
    {
        return str_starts_with($this->value, 'user.') ? 'user' : 'run';
    }

    /**
     * Entries worth making stand out: something was destroyed, or someone gained
     * power over the system.
     */
    public function isSevere(): bool
    {
        return match ($this) {
            self::RUN_DELETED, self::USER_PROMOTED, self::USER_DEACTIVATED => true,
            default => false,
        };
    }
}
