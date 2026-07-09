<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\EstimateValidationStatus;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Estimate\Estimate;
use App\Notifications\PipelineAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Перенос v1-наблюдаемости (`Old/app/Console/Commands/PipelineStuckCheckCommand.php`) на
 * v2-модель (документы/AI-анализы/сметы вместо ссылок/DocumentAnalysis, ТЗ 03 §5): каждая
 * проверка — маленький read-only метод (Implementation Rules — команда не чинит состояние,
 * только сигналит; починка зависших стадий — `pipeline:retry-failed`, P4.3). Инфра-проверки
 * v1 (Redis/queue-depth//dev/shm) — Scope Excluded, принадлежат краулинг-стеку (carry-over).
 *
 * Находки агрегируются в ОДНО Telegram-сообщение через `PipelineAlert::notify()`
 * (Code Guidance) с антиспамом по ключу находки (не чаще раза в час на одинаковую находку).
 */
final class PipelineStuckCheck extends Command
{
    protected $signature = 'pipeline:stuck-check';

    protected $description = 'Проверяет зависшие/аномальные состояния пайплайна и алертит агрегированным сообщением';

    public function __construct(private readonly PipelineAlert $alert)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $findings = [
            ...$this->stuckAnalyses(step: AnalysisStep::Screening, configKey: 'analysis_stuck_minutes', findingKey: 'stuck_screening'),
            ...$this->stuckAnalyses(step: AnalysisStep::Extraction, configKey: 'checkpoint_stuck_minutes', findingKey: 'stuck_extraction'),
            ...$this->stuckDocuments(),
            ...$this->unknownSpike(),
            ...$this->unprocessableSpike(),
            ...$this->estimateMismatchSpike(),
            ...$this->eolModels(),
            ...$this->backlogSpike(),
            ...$this->failureRateSpike(),
            ...$this->noCompletions(),
        ];

        if ($findings === []) {
            $this->info('Зависших/аномальных состояний не обнаружено.');

            return self::SUCCESS;
        }

        foreach ($findings as $message) {
            $this->warn($message);
        }

        $this->alert->notify($findings);

        return self::SUCCESS;
    }

    /**
     * Анализы в `running` дольше порога при истёкшем лизе (D31): extraction получает СВОЙ,
     * обычно больший порог (`checkpoint_stuck_minutes`) — self-requeue-цепочка легитимно
     * проходит через паузы между checkpoint-прогонами дольше, чем single-shot screening
     * (`analysis_stuck_minutes`); разные конфиг-ключи/finding-ключи не дают двум сигналам
     * схлопнуться в один при совместной агрегации.
     *
     * @return array<string, string>
     */
    private function stuckAnalyses(AnalysisStep $step, string $configKey, string $findingKey): array
    {
        $minutes = (int) config("ai.pipeline.{$configKey}", 30);

        $count = Analysis::query()
            ->where('step', $step->value)
            ->where('status', AnalysisStatus::Running->value)
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->count();

        if ($count === 0) {
            return [];
        }

        return [$findingKey => "Зависших анализов ({$step->value}, running >{$minutes}мин, лиз истёк): {$count}"];
    }

    /**
     * Документы, застрявшие в downloaded/prepared/screened сверх порога — разрыв моста между
     * стадиями (screened-затор возможен при выключенном `ai.extraction.enabled` или
     * перманентных фейлах extraction, ревью K п.20). `failed_permanently` исключены —
     * dead-letter не подбирается ни этой проверкой, ни `pipeline:retry-failed`.
     *
     * @return array<string, string>
     */
    private function stuckDocuments(): array
    {
        $minutes = (int) config('ai.pipeline.document_stuck_minutes', 60);

        $rows = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $breakdown = $rows->map(fn ($row): string => "{$row->status}={$row->total}")->implode(', ');

        return ['stuck_documents' => "Документов застряло на стадии >{$minutes}мин: {$breakdown}"];
    }

    /**
     * Всплеск `unknown`-веток скрининга (`ScreeningOutcomeHandler::branchByType()`) за окно —
     * возможен дрейф классификатора/новый тип документа (ТЗ 03 §5).
     *
     * @return array<string, string>
     */
    private function unknownSpike(): array
    {
        $windowHours = (int) config('ai.pipeline.spike_window_hours', 24);
        $threshold = (int) config('ai.pipeline.unknown_spike_threshold', 20);

        $count = Analysis::query()
            ->where('step', AnalysisStep::Screening->value)
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->whereRaw("result->>'branch_reason' = ?", ['unknown'])
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['unknown_spike' => "Всплеск unknown в скрининге за {$windowHours}ч: {$count} (порог {$threshold})"];
    }

    /**
     * Всплеск терминального `unprocessable` (D32) за окно — вводная владельца «упёрлись в
     * лимит провайдера → сразу видно в системе».
     *
     * @return array<string, string>
     */
    private function unprocessableSpike(): array
    {
        $windowHours = (int) config('ai.pipeline.spike_window_hours', 24);
        $threshold = (int) config('ai.pipeline.unprocessable_spike_threshold', 5);

        $count = Document::query()
            ->where('status', DocumentStatus::Unprocessable->value)
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['unprocessable_spike' => "Всплеск unprocessable за {$windowHours}ч: {$count} (порог {$threshold})"];
    }

    /**
     * Расхождение rows_expected/extracted (ТЗ 03 §5) — сметы, детерминированно помеченные
     * `EstimateValidator` (P6.2) как suspicious/invalid, за окно.
     *
     * @return array<string, string>
     */
    private function estimateMismatchSpike(): array
    {
        $windowHours = (int) config('ai.pipeline.spike_window_hours', 24);
        $threshold = (int) config('ai.pipeline.estimate_mismatch_threshold', 5);

        $count = Estimate::query()
            ->whereIn('validation_status', [EstimateValidationStatus::Suspicious->value, EstimateValidationStatus::Invalid->value])
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['estimate_mismatch_spike' => "Расхождение rows_expected/extracted за {$windowHours}ч: {$count} suspicious/invalid смет (порог {$threshold})"];
    }

    /**
     * Модель активного маршрута ближе `config('ai.model_eol_warn_days', 30)` дней к `eol_date`
     * (D27) — защита от «модель молча умерла»; отрицательное число дней — модель УЖЕ прошла
     * `eol_date` (условие `<=` покрывает и этот случай).
     *
     * @return array<string, string>
     */
    private function eolModels(): array
    {
        $warnDays = (int) config('ai.model_eol_warn_days', 30);
        $now = CarbonImmutable::now();
        $capabilities = (array) config('ai.capabilities', []);

        $models = AiRoute::query()->where('is_active', true)->pluck('model')->unique()->filter();

        $near = [];

        foreach ($models as $model) {
            $eolDate = $capabilities[$model]['eol_date'] ?? null;

            if ($eolDate === null) {
                continue;
            }

            $eol = CarbonImmutable::parse($eolDate);
            $daysLeft = (int) $now->diffInDays($eol, absolute: true);

            if ($eol->isPast()) {
                $daysLeft = -$daysLeft;
            }

            if ($daysLeft <= $warnDays) {
                $successor = $capabilities[$model]['successor'] ?? '?';
                $near[] = "{$model} (осталось {$daysLeft}д, преемник {$successor})";
            }
        }

        if ($near === []) {
            return [];
        }

        return ['model_eol' => 'Модели активных маршрутов близки к EOL: '.implode('; ', $near)];
    }

    /**
     * Backlog (P7.5, D25 Intent п.5) — документов одновременно в downloaded/prepared/screened
     * (ждут следующей стадии) БЕЗ временного порога, в отличие от `stuckDocuments()` (там —
     * возраст конкретных документов); здесь — суммарный объём очереди.
     *
     * @return array<string, string>
     */
    private function backlogSpike(): array
    {
        $threshold = (int) config('ai.pipeline.backlog_threshold', 200);

        $count = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['backlog' => "Backlog документов (downloaded/prepared/screened): {$count} (порог {$threshold})"];
    }

    /**
     * Доля failed среди завершённых анализов (оба шага) за короткое окно (P7.5, D25 Intent п.5)
     * — быстрый сигнал массового провала (например, протухший API-ключ), в отличие от
     * `spike_window_hours`-счётчиков (сутки).
     *
     * @return array<string, string>
     */
    private function failureRateSpike(): array
    {
        $windowHours = (int) config('ai.pipeline.failure_rate_window_hours', 1);
        $thresholdPercent = (float) config('ai.pipeline.failure_rate_threshold_percent', 50.0);

        $totals = Analysis::query()
            ->whereIn('status', [AnalysisStatus::Succeeded->value, AnalysisStatus::Failed->value])
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->pluck('total', 'status');

        $succeeded = (int) ($totals[AnalysisStatus::Succeeded->value] ?? 0);
        $failed = (int) ($totals[AnalysisStatus::Failed->value] ?? 0);
        $total = $succeeded + $failed;

        if ($total === 0) {
            return [];
        }

        $rate = round($failed / $total * 100, 1);

        if ($rate <= $thresholdPercent) {
            return [];
        }

        return ['failure_rate' => "Failure rate анализов за {$windowHours}ч: {$rate}% ({$failed}/{$total}, порог {$thresholdPercent}%)"];
    }

    /**
     * «Нет завершений стадии N минут» (P7.5, D25 Intent п.5) — ни одного succeeded-анализа за
     * окно ПРИ НЕПУСТОМ backlog (иначе «нечего обрабатывать» ложно читалось бы как «встал»).
     *
     * @return array<string, string>
     */
    private function noCompletions(): array
    {
        $minutes = (int) config('ai.pipeline.no_completions_minutes', 120);

        $hasBacklog = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->exists();

        if (! $hasBacklog) {
            return [];
        }

        $recentCompletions = Analysis::query()
            ->where('status', AnalysisStatus::Succeeded->value)
            ->where('updated_at', '>=', now()->subMinutes($minutes))
            ->exists();

        if ($recentCompletions) {
            return [];
        }

        return ['no_completions' => "Ни одного завершённого анализа за {$minutes}мин при непустом backlog — пайплайн, похоже, встал"];
    }
}
