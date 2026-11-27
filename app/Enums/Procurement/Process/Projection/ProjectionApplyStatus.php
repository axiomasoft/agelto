<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Projection;

/**
 * Исход live apply одного event одним reducer. Settled — ничего больше делать не нужно (outbox
 * можно подтверждать); Maintenance откладывает доставку; остальное оставляет событие unapplied с
 * видимым кодом (degraded + incident), но не блокирует другие event IDs.
 */
enum ProjectionApplyStatus: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Maintenance = 'maintenance';
    case SourceUnavailable = 'source_unavailable';
    case SchemaUnsupported = 'schema_unsupported';
    case SchemaInvalid = 'schema_invalid';
    case CatalogMismatch = 'catalog_mismatch';

    public function settled(): bool
    {
        return match ($this) {
            self::Applied, self::Duplicate, self::SourceUnavailable => true,
            self::Maintenance, self::SchemaUnsupported, self::SchemaInvalid, self::CatalogMismatch => false,
        };
    }

    /** Требует quarantine сообщения и incident, а не короткой отсрочки. */
    public function quarantined(): bool
    {
        return match ($this) {
            self::SchemaUnsupported, self::SchemaInvalid, self::CatalogMismatch => true,
            self::Applied, self::Duplicate, self::Maintenance, self::SourceUnavailable => false,
        };
    }
}
