<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Llm\PromptCanonSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Тонкий вызыватель `PromptCanonSynchronizer::sync()` —
 * разбор канона и запись живут в `App\Services\Llm\{PromptCanonReader,PromptCanonSynchronizer}`,
 * сидер существует ради совместимости с `db:seed`/`DatabaseSeeder::call()`.
 */
final class PromptSeeder extends Seeder
{
    public function __construct(private readonly PromptCanonSynchronizer $synchronizer) {}

    public function run(): void
    {
        $this->synchronizer->sync();
    }
}
