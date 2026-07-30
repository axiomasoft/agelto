<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum PromptSuggestionPermission: string
{
    case ViewAny = 'prompt_suggestion.view_any';
    case View = 'prompt_suggestion.view';
    case Create = 'prompt_suggestion.create';
    case Update = 'prompt_suggestion.update';
    case Delete = 'prompt_suggestion.delete';
    case Restore = 'prompt_suggestion.restore';
    case ForceDelete = 'prompt_suggestion.force_delete';
    case Replicate = 'prompt_suggestion.replicate';
    case Reorder = 'prompt_suggestion.reorder';
}
