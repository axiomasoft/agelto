<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Services\Llm\Validation\SemanticValidator;
use Spatie\LaravelData\Data;

/** @implements SemanticValidator<ScreeningResult> */
final class ScreeningSemanticValidator implements SemanticValidator
{
    public function errors(Data $data): array
    {
        $errors = [];
        if ($data->confidence < 0 || $data->confidence > 1 || ! is_finite($data->confidence)) {
            $errors[] = 'screening_confidence_out_of_bounds';
        }
        if (trim($data->summary) === '' || mb_strlen($data->summary) > 1000 || count($data->tags) > 12) {
            $errors[] = 'screening_text_out_of_bounds';
        }
        foreach ($data->tags as $tag) {
            if (trim($tag) === '' || mb_strlen($tag) > 64) {
                $errors[] = 'screening_tag_out_of_bounds';
            }
        }
        if ($data->classification_note !== null && (trim($data->classification_note) === '' || mb_strlen($data->classification_note) > 500)) {
            $errors[] = 'screening_note_out_of_bounds';
        }
        if (($error = $data->structuralError()) !== null) {
            $errors[] = 'screening_cardinality_conflict';
        }

        return array_values(array_unique($errors));
    }
}
