<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Вид предложения критика (D60): `PromptPatch` — полный текст нового тела файла промта,
 * `CorrectionHint` — переиспользуемая подсказка (существующий носитель `prc_ai_corrections`).
 */
enum PromptSuggestionKind: string
{
    case PromptPatch = 'prompt_patch';
    case CorrectionHint = 'correction_hint';
}
