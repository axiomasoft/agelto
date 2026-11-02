<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Enums\Llm\OperationPurpose;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Str;

final readonly class RequestIdentity
{
    public string $logicalKey;

    public function __construct(
        public string $recipeHash,
        public string $inputHash,
        public OperationPurpose $purpose,
        public int $semanticReaskOrdinal,
        public string $replayIntentId,
    ) {
        foreach ([$recipeHash, $inputHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Invalid request hash.');
            }
        }
        if ($semanticReaskOrdinal < 0 || $semanticReaskOrdinal > 1 || ! Str::isUuid($replayIntentId)) {
            throw new \InvalidArgumentException('Invalid semantic/replay identity.');
        }
        $this->logicalKey = CanonicalJson::hash(['version' => 1, 'recipe_hash' => $recipeHash, 'input_hash' => $inputHash,
            'purpose' => $purpose->value, 'semantic_reask_ordinal' => $semanticReaskOrdinal, 'replay_intent_id' => $replayIntentId]);
    }
}
