<?php

declare(strict_types=1);

namespace App\Models\Llm;

use Database\Factories\Llm\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Конфигурация LLM-провайдера. Ключи API в БД не хранятся — только имя секрета (env/secrets).
 *
 * @property string $code
 * @property string $driver
 * @property string|null $base_url
 * @property Collection<string, mixed>|null $options
 */
#[UseFactory(ProviderFactory::class)]
#[Fillable([
    'code',
    'driver',
    'base_url',
    'options',
    'is_active',
])]
#[Table(name: 'llm_providers')]
final class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'options' => AsCollection::class,
            'is_active' => 'boolean',
        ];
    }
}
