<?php

declare(strict_types=1);

namespace App\Models\Llm;

use Database\Factories\Llm\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Конфигурация LLM-провайдера. Ключи API в БД не хранятся — только имя секрета (env/secrets).
 */
#[UseFactory(ProviderFactory::class)]
final class Provider extends Model
{
    use HasFactory;

    protected $table = 'llm_providers';

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
