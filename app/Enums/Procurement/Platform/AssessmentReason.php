<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum AssessmentReason: string
{
    case Valid = 'valid';
    case Empty = 'empty';
    case InvalidResponse = 'invalid_response';
    case SchemaMismatch = 'schema_mismatch';
    case IdentityMismatch = 'identity_mismatch';
    case FilterMismatch = 'filter_mismatch';
    case Overflow = 'overflow';
    case RepeatedPage = 'repeated_page';
    case MissingSection = 'missing_section';
    case UnstableSource = 'unstable_source';
}
