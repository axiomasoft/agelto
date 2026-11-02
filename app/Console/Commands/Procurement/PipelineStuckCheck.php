<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Models\Llm\LlmRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Estimate\Estimate;
use App\Notifications\PipelineAlert;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

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
#[Description('Проверяет зависшие/аномальные состояния пайплайна и алертит агрегированным сообщением')]
#[Signature('pipeline:stuck-check')]
final class PipelineStuckCheck extends Command
{
    public function __construct(private readonly PipelineAlert $alert)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $findings = [
            ...$this->stuckAnalyses(step: AnalysisStep::Screening, configKey: 'analysis_stuck_minutes', findingKey: 'stuck_screening'),
            ...$this->stuckAnalyses(step: AnalysisStep::Extraction, configKey: 'checkpoint_stuck_minutes', findingKey: 'stuck_extraction'),
            ...$this->stuckRuns(),
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
        $minutes = $this->intConfig("ai.pipeline.{$configKey}", 30);

        $count = DB::table('prc_process_work')->where('stage', $step->value)->where('state', 'running')
            ->whereRaw('lease_until<clock_timestamp()')->whereRaw("updated_at<clock_timestamp()-(? * interval '1 minute')", [$minutes])->count();

        if ($count === 0) {
            return [];
        }

        return [$findingKey => "Зависших анализов ({$step->value}, running >{$minutes}мин, лиз истёк): {$count}"];
    }

    /** @return array<string,string> */
    private function stuckRuns(): array
    {
        $minutes = $this->intConfig('ai.pipeline.document_stuck_minutes', 60);

        $count = app(AnalysisRunReadRepository::class)->query()
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('prc_process_work as w')
                ->whereColumn('w.document_version_id', 'prc_analysis_runs.document_version_id')
                ->whereColumn('w.input_revision', 'prc_analysis_runs.expected_analysis_generation')
                ->where('w.stage', 'screening')->whereIn('w.state', ['ready', 'admitted', 'running', 'deferred'])
                ->whereRaw("w.updated_at<clock_timestamp()-(? * interval '1 minute')", [$minutes]))->count();

        if ($count === 0) {
            return [];
        }

        return ['stuck_runs' => "Незакрытых прогонов (>{$minutes}мин): {$count}"];
    }

    /**
     * Документы, застрявшие в discovered/downloaded/prepared/screened сверх порога — разрыв
     * моста между стадиями (screened-затор возможен при выключенном `ai.extraction.enabled` или
     * перманентных фейлах extraction, ревью K п.20). `discovered` — Д-5 (P7.7, research/10 §3):
     * провал СКАЧИВАНИЯ раньше не попадал ни в одну проверку — единственный канал, который сам
     * приходит к человеку (`PipelineAlert`), был слеп к этой стадии. `failed_permanently`
     * исключены — dead-letter не подбирается ни этой проверкой, ни `pipeline:retry-failed`.
     *
     * @return array<string, string>
     */
    private function stuckDocuments(): array
    {
        $minutes = $this->intConfig('ai.pipeline.document_stuck_minutes', 60);

        $rows = Document::query()
            ->whereIn('status', [DocumentStatus::Discovered->value, DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $breakdown = $rows->map(fn (object $row): string => sprintf(
            '%s=%d',
            $this->stringValue($row->status, 'document status'),
            $this->integerValue($row->total, 'document count'),
        ))->implode(', ');

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
        $windowHours = $this->intConfig('ai.pipeline.spike_window_hours', 24);
        $threshold = $this->intConfig('ai.pipeline.unknown_spike_threshold', 20);

        $count = Analysis::query()
            ->where('step', AnalysisStep::Screening->value)
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->whereRaw("result->'decision'->>'reason' = ?", [ScreeningDecisionReason::UnknownType->value])
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
        $windowHours = $this->intConfig('ai.pipeline.spike_window_hours', 24);
        $threshold = $this->intConfig('ai.pipeline.unprocessable_spike_threshold', 5);

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
        $windowHours = $this->intConfig('ai.pipeline.spike_window_hours', 24);
        $threshold = $this->intConfig('ai.pipeline.estimate_mismatch_threshold', 5);

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
        $warnDays = $this->intConfig('ai.model_eol_warn_days', 30);
        $now = CarbonImmutable::now();
        $capabilities = $this->capabilities();

        $models = LlmRoute::query()->where('is_active', true)->distinct()->pluck('model');

        $near = [];

        foreach ($models as $model) {
            if (! is_string($model) || $model === '') {
                continue;
            }

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
        $threshold = $this->intConfig('ai.pipeline.backlog_threshold', 200);

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
        $windowHours = $this->intConfig('ai.pipeline.failure_rate_window_hours', 1);
        $thresholdPercent = $this->floatConfig('ai.pipeline.failure_rate_threshold_percent', 50.0);
        $since = now()->subHours($windowHours);

        $terminal = DB::table('prc_process_transitions as t')->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')->whereIn('w.stage', ['screening', 'extraction'])
            ->where('t.occurred_at', '>=', $since);
        $succeeded = (clone $terminal)->where('t.new_state', 'succeeded')
            ->count();
        $failed = (clone $terminal)->where('t.new_state', 'failed_item')
            ->count();
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
        $minutes = $this->intConfig('ai.pipeline.no_completions_minutes', 120);

        $hasBacklog = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->exists();

        if (! $hasBacklog) {
            return [];
        }

        $recentCompletions = DB::table('prc_process_transitions as t')->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')->whereIn('w.stage', ['screening', 'extraction'])
            ->where('t.new_state', 'succeeded')->where('t.occurred_at', '>=', now()->subMinutes($minutes))
            ->exists();

        if ($recentCompletions) {
            return [];
        }

        return ['no_completions' => "Ни одного завершённого анализа за {$minutes}мин при непустом backlog — пайплайн, похоже, встал"];
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new LogicException("{$key} должен быть целым числом.");
        }

        return $value;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if (! is_float($value) && ! is_int($value)) {
            throw new LogicException("{$key} должен быть числом.");
        }

        return (float) $value;
    }

    private function integerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException("{$context} должен быть целым числом.");
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new LogicException("{$context} должен быть строкой.");
        }

        return $value;
    }

    /**
     * @return array<string, array{eol_date?: string, successor?: string}>
     */
    private function capabilities(): array
    {
        $value = config('ai.capabilities', []);

        if (! is_array($value)) {
            throw new LogicException('ai.capabilities должен быть массивом моделей.');
        }

        $capabilities = [];

        foreach ($value as $model => $attributes) {
            if (! is_string($model) || ! is_array($attributes)) {
                throw new LogicException('Каждая capability должна иметь строковый код модели и массив атрибутов.');
            }

            $capability = [];
            $eolDate = $attributes['eol_date'] ?? null;
            $successor = $attributes['successor'] ?? null;

            if ($eolDate !== null) {
                $capability['eol_date'] = $this->stringValue($eolDate, "eol_date модели {$model}");
            }

            if ($successor !== null) {
                $capability['successor'] = $this->stringValue($successor, "successor модели {$model}");
            }

            $capabilities[$model] = $capability;
        }

        return $capabilities;
    }
}
