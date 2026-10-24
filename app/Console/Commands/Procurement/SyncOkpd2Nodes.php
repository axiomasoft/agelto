<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Platforms\Eis\Filters\Okpd2NodeSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Тонкая точка входа пересборки карты «код ОКПД2 → id НСИ» (P2.5, D14): разбор опций, вызов
 * синхронизатора, печать отчёта и код возврата. Обход и сверка живут в
 * `Okpd2NodeSynchronizer`, запись — в `Okpd2NodeStoreRepository`.
 */
#[Description('Пересобирает карту «код ОКПД2 → id НСИ» с ЕИС и показывает дрейф id')]
#[Signature('crawler:okpd2-sync
        {--root= : id узла-корня НСИ (дефолт — config(crawler.okpd2.root_external_id), раздел F)}
        {--dry-run : только отчёт, без записи в prc_okpd2_nodes}
        {--max-requests= : потолок запросов к площадке (дефолт — config(crawler.okpd2.max_requests))}')]
final class SyncOkpd2Nodes extends Command
{
    public function handle(Okpd2NodeSynchronizer $synchronizer): int
    {
        $root = $this->option('root');
        $maxRequests = $this->option('max-requests');

        $report = $synchronizer->run(
            root: is_scalar($root) && (string) $root !== '' ? (string) $root : null,
            dryRun: (bool) $this->option('dry-run'),
            maxRequests: is_numeric($maxRequests) ? (int) $maxRequests : null,
        );

        $this->line(sprintf(
            'Запросов к площадке: %d · узлов снято: %d · режим: %s',
            $report['requests'],
            $report['nodes_seen'],
            $report['written'] ? 'запись' : 'dry-run (карта не тронута)',
        ));

        $this->line(sprintf(
            'Новых: %d · исчезнувших: %d · сменивших id: %d',
            count($report['new']),
            count($report['missing']),
            count($report['changed']),
        ));

        foreach ($report['new'] as $node) {
            $this->line("  + новый узел {$node['code']} (id {$node['external_id']})");
        }

        foreach ($report['missing'] as $node) {
            $this->line("  ? не пришёл узел {$node['code']} (сохранённый id {$node['external_id']}) — из таблицы НЕ удалён");
        }

        foreach ($report['changed'] as $node) {
            $this->line("  ! сменился id узла {$node['code']}: {$node['stored_external_id']} → {$node['external_id']}");
        }

        // Остаток обхода — не ошибка, а сознательное ограничение потолком (Scope Excluded P2.5):
        // называется явно, потому что «узла нет» и «до узла не дошли» — разные вещи.
        if ($report['not_traversed'] !== []) {
            $this->warn(sprintf(
                'Не обойдено (потолок запросов): %d узлов — %s',
                count($report['not_traversed']),
                implode(', ', $report['not_traversed']),
            ));
        }

        if ($report['drifted_active_codes'] === []) {
            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Дрейф затронул коды АКТИВНЫХ профилей: %s. Пересоберите карту целиком и проверьте выборку — '
            .'расхождение одной пары (код, id) означает перенумерацию диапазона.',
            implode(', ', $report['drifted_active_codes']),
        ));

        return self::FAILURE;
    }
}
