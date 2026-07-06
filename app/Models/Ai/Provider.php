<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Database\Factories\Ai\ProviderFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Конфигурация LLM-провайдера. Ключи API в БД не хранятся — только имя секрета (env/secrets).
 *
 * @use HasFactory<ProviderFactory>
 */
final class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    protected $table = 'prc_ai_providers';

    protected $fillable = [
        'code',
        'driver',
        'base_url',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => AsCollection::class,
            'is_active' => 'boolean',
        ];
    }
}
