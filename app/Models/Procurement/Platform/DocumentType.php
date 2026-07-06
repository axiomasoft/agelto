<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use Database\Factories\Procurement\Platform\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Справочник типов документов (открытый, наполняется без миграций).
 */
#[UseFactory(DocumentTypeFactory::class)]
final class DocumentType extends Model
{
    use HasFactory;

    protected $table = 'prc_document_types';

    protected $fillable = [
        'code',
        'name',
        'has_extractor',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_extractor' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
