<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Database\Factories\Platform\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Справочник типов документов (открытый, наполняется без миграций).
 *
 * @use HasFactory<DocumentTypeFactory>
 */
final class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $table = 'plt_document_types';

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
