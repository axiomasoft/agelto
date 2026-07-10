<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Procurement\PromptStep;
use App\Models\Procurement\Ai\Prompt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Читает `resources/ai/prompts/**\/*.md` (front-matter: key/step/version/priority/is_active) и
 * upsert'ит в `prc_ai_prompts` по `(key, version)` — файлы под git являются каноном, БД — их
 * рантайм-копия (D5/D17, `docs/specs/v2/08-estimate-extraction.md` §промты). Все строки,
 * записанные этим сидером, получают `is_protected=true` — редактируются только через файл + повтор
 * `db:seed`/`ai:prompts:sync`, никогда напрямую в БД (P8.2 закрепляет это на уровне Policy).
 *
 * Идемпотентность: повторный запуск не плодит записей (upsert по `(key, version)`); поднятая в
 * front-matter `version` создаёт НОВУЮ строку, а предыдущие версии того же `key` деактивируются
 * (`is_active=false`) — история версий остаётся в БД, не удаляется. Файл, исчезнувший из
 * `resources/ai/prompts/**`, деактивирует ВСЕ версии своего `key` тем же способом — не delete.
 * Оператор-промты (`is_protected=false`) сидер никогда не трогает — они вне множества файлов.
 */
final class PromptSeeder extends Seeder
{
    public function run(): void
    {
        $records = self::loadFiles();

        foreach ($records as $record) {
            Prompt::query()->updateOrCreate(
                ['key' => $record['key'], 'version' => $record['version']],
                [
                    'step' => $record['step'],
                    'content' => $record['content'],
                    'priority' => $record['priority'],
                    'is_active' => $record['is_active'],
                    'is_protected' => true,
                ],
            );
        }

        $latestVersionByKey = $records->groupBy('key')
            ->map(fn (Collection $group): int => (int) $group->max('version'));

        // Протухшие версии того же key (front-matter version подняли — старая остаётся в БД,
        // но неактивна) + protected-записи, чей key вообще исчез из файлов (delete файла).
        Prompt::query()
            ->where('is_protected', true)
            ->where('is_active', true)
            ->get()
            ->each(function (Prompt $prompt) use ($latestVersionByKey): void {
                $latest = $latestVersionByKey->get($prompt->key);

                if ($latest === null || $prompt->version < $latest) {
                    $prompt->update(['is_active' => false]);
                }
            });
    }

    /**
     * Разбор всех файлов `resources/ai/prompts/**\/*.md` — переиспользуется `ai:prompts:sync`
     * (`PromptsSyncCommand`) для дрейф-проверки БЕЗ записи в БД.
     *
     * @return Collection<int, array{key: string, step: string, content: string, version: int, priority: int, is_active: bool}>
     */
    public static function loadFiles(): Collection
    {
        $paths = glob(resource_path('ai/prompts/*/*.md')) ?: [];

        return collect($paths)
            ->sort()
            ->values()
            ->map(fn (string $path): array => self::parseFile($path));
    }

    /**
     * @return array{key: string, step: string, content: string, version: int, priority: int, is_active: bool}
     */
    private static function parseFile(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать файл промта: {$path}");
        }

        if (! preg_match('/\A---\n(?<front>.*?)\n---\n+(?<content>.*)\z/s', $raw, $matches)) {
            throw new RuntimeException("Файл промта без front-matter (---...---): {$path}");
        }

        $front = [];
        foreach (preg_split('/\n/', trim($matches['front'])) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $front[trim($name)] = trim($value);
        }

        foreach (['key', 'step', 'version', 'priority', 'is_active'] as $required) {
            if (! array_key_exists($required, $front)) {
                throw new RuntimeException("Файл промта без обязательного поля front-matter «{$required}»: {$path}");
            }
        }

        // Fail fast на опечатку в step — обязана совпадать с одним из 8 значений PromptStep.
        PromptStep::from($front['step']);

        $content = trim($matches['content']);

        if ($content === '') {
            throw new RuntimeException("Файл промта с пустым содержимым (после front-matter): {$path}");
        }

        return [
            'key' => $front['key'],
            'step' => $front['step'],
            'content' => $content,
            'version' => (int) $front['version'],
            'priority' => (int) $front['priority'],
            'is_active' => filter_var($front['is_active'], FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
