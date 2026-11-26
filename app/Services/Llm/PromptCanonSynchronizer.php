<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Llm\Prompt;
use App\Repositories\Llm\PromptReadRepository;
use App\Repositories\Llm\PromptStoreRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Апсерт канона промтов в `llm_prompts` + деактивация протухших версий (`sync`) и сверка
 * файлы↔БД без записи (`drift`) — владелец решения, ЧТО делать с прочитанными записями; сам разбор файлов не знает — за ним к `PromptCanonReader`.
 */
final readonly class PromptCanonSynchronizer
{
    public function __construct(
        private PromptCanonReader $reader,
        private PromptStoreRepository $store,
        private PromptReadRepository $read,
    ) {}

    public function sync(): void
    {
        $records = $this->reader->read();

        DB::transaction(function () use ($records): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('llm_prompt_canon'))");
            foreach ($records as $record) {
                $this->store->storeOrUpdate($record);
            }

            $this->deactivateStale($records);
        });
    }

    /**
     * Дрейф файлы↔БД без записи (`ai:prompts:sync --check`) — строки готовы к печати командой,
     * сервис сам ничего не выводит.
     *
     * @return list<string>
     */
    public function drift(): array
    {
        $records = $this->reader->read();
        $drift = [];

        foreach ($records as $record) {
            $existing = $this->read->findVersion($record['document_type'], $record['key'], $record['version']);

            if ($existing === null) {
                $drift[] = "новый: {$record['document_type']}/{$record['key']} v{$record['version']} (нет в БД)";

                continue;
            }

            $mismatched = $existing->step !== $record['step']
                || $existing->content !== $record['content']
                || $existing->priority !== $record['priority']
                || $existing->is_active !== $record['is_active']
                || ! $existing->is_protected;

            if ($mismatched) {
                $drift[] = "изменён: {$record['document_type']}/{$record['key']} v{$record['version']} (файл ≠ БД)";
            }
        }

        $latestVersionByPair = $this->latestVersionByPair($records);

        foreach ($this->read->activeProtected() as $prompt) {
            $latest = $latestVersionByPair->get($this->pairKeyOf($prompt));

            if ($latest === null) {
                $drift[] = "удалён из файлов, но активен в БД: {$prompt->document_type}/{$prompt->key} v{$prompt->version}";
            } elseif ($prompt->version < $latest) {
                $drift[] = "устаревшая версия ещё активна в БД: {$prompt->document_type}/{$prompt->key} v{$prompt->version} (файл v{$latest})";
            } elseif ($prompt->version > $latest) {
                $drift[] = "откат канона: активна версия новее файла: {$prompt->document_type}/{$prompt->key} v{$prompt->version} (файл v{$latest})";
            }
        }

        return $drift;
    }

    /**
     * @param  Collection<int, array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}>  $records
     */
    private function deactivateStale(Collection $records): void
    {
        $latestVersionByPair = $this->latestVersionByPair($records);

        foreach ($this->read->activeProtected() as $prompt) {
            $latest = $latestVersionByPair->get($this->pairKeyOf($prompt));

            if ($latest === null || $prompt->version !== $latest) {
                $this->store->deactivate($prompt);
            }
        }
    }

    /**
     * @param  Collection<int, array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}>  $records
     * @return Collection<string, int>
     */
    private function latestVersionByPair(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (array $record): string => "{$record['document_type']}\0{$record['key']}")
            ->map(function (Collection $group): int {
                $version = $group->max('version');

                return is_int($version) || is_float($version) || is_string($version) ? (int) $version : 0;
            });
    }

    private function pairKeyOf(Prompt $prompt): string
    {
        return "{$prompt->document_type}\0{$prompt->key}";
    }
}
