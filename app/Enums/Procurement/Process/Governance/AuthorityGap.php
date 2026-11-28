<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/**
 * Почему unlocked pre-read не смог собрать полный AuthoritySnapshot. Отсутствие authority row не
 * означает enabled: grant не выдаётся, scopes создаёт отдельная ensureScopes, не admission.
 */
enum AuthorityGap: string
{
    case WorkMissing = 'work_missing';
    case HandlerUnregistered = 'handler_unregistered';
    case HandlerAuthorityMissing = 'handler_authority_missing';
    case ScopesMissing = 'scopes_missing';
    case LanesMissing = 'lanes_missing';
    case SubjectMissing = 'subject_missing';
}
