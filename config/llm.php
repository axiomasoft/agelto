<?php

declare(strict_types=1);

return [
    // Explicit operator-provisioned identities/pricing. Empty configuration creates no paid work.
    'analysis_profile' => env('LLM_ANALYSIS_PROFILE'),
    'profiles_json' => env('LLM_ANALYSIS_PROFILES_JSON', '{}'),
    'credentials' => ['primary' => env('GEMINI_API_KEY', '')],
];
