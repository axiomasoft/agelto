<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;

/**
 * Содержимое модалки результата прогона: вердикт первичного анализа, сводка
 * разбора, полученная ОТ АНАЛИЗАТОРА типа через реестр, и эталон рядом.
 *
 * Витрина спрашивает анализатор, а не предметные таблицы: единственный источник содержимого
 * разбора — `DocumentAnalyzer::summarize`, отдающий плоский `AnalysisSummary`. Разметку делает этот класс и его blade — данные приходят
 * DTO, HTML анализатор не отдаёт.
 *
 * Токены и цена отображаются в строке прогона (`RunsRelationManager`).
 *
 * Данные резолвятся ОДИН раз на сборке схемы (`$run`/`$fixture` известны в момент монтирования
 * действия), а не замыканием в каждом entry: иначе один показ модалки давал бы столько запросов,
 * сколько в ней полей.
 */
final class RunResultSchema
{
    /**
     * Потолок показа строк сводки: большой результат не рендерится целиком,
     * факт обрезания печатается явно.
     */
    private const int MAX_METRICS = 20;

    /** Тот же потолок для JSON эталона — в строках. */
    private const int MAX_GOLD_LINES = 40;

    public static function configure(Schema $schema, Fixture $fixture, AnalysisRun $run): Schema
    {
        return $schema->components([
            self::screeningSection(self::analysisOfStep($run, AnalysisStep::Screening)),
            self::summarySection(self::summaryState($run)),
            self::goldSection(self::gold($fixture, $run)),
        ]);
    }

    private static function screeningSection(?Analysis $screening): Section
    {
        // Форма `?? null` — не украшение (тот же приём, что `EstimateAnalyzer::summarize`): вне
        // `??`-контекста Larastan не резолвит тип свойства модели и заводит `property.notFound`.
        $error = null; // Execution failures are exposed by the authoritative process workflow, not result rows.

        return Section::make('Первичный анализ')
            ->columns(2)
            ->schema([
                TextEntry::make('screening_status')
                    ->label('Статус')
                    ->state($screening?->status)
                    ->badge()
                    ->placeholder('первичный анализ не выполнялся'),
                TextEntry::make('screening_document_type')
                    ->label('Вердикт: тип документа')
                    ->state($screening?->document_type)
                    ->placeholder('тип не определён'),
                TextEntry::make('screening_confidence')
                    ->label('Уверенность')
                    ->state(self::confidence($screening))
                    ->placeholder('нет данных'),
                TextEntry::make('screening_branch')
                    ->label('Решение backend')
                    ->state(self::decision($screening))
                    ->placeholder('—'),
                TextEntry::make('screening_description')
                    ->label('Краткое содержание')
                    ->state(self::summary($screening))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('screening_tags')
                    ->label('Теги')
                    ->state(self::tags($screening))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('screening_error')
                    ->label('Ошибка')
                    ->state($error)
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summarySection(array $summary): Section
    {
        return Section::make('Разбор документа')
            ->schema([
                ViewEntry::make('summary')
                    ->hiddenLabel()
                    ->view('filament.resources.fixtures.run-result')
                    ->state($summary)
                    ->columnSpanFull(),
            ]);
    }

    private static function goldSection(?DatasetExample $gold): Section
    {
        $task = $gold === null ? null : ($gold->task ?? null);
        $version = $gold === null ? null : ($gold->version ?? null);
        $source = $gold === null ? null : ($gold->source ?? null);

        return Section::make('Эталон')
            ->columns(3)
            ->schema([
                TextEntry::make('gold_absent')
                    ->hiddenLabel()
                    ->state($gold === null
                        ? 'Эталона нет: он заводится командой `estimate:gold:import` из gold-файла корпуса, из админки эталон не правится.'
                        : null)
                    ->visible($gold === null)
                    ->columnSpanFull(),
                TextEntry::make('gold_task')
                    ->label('Задача')
                    ->state($task)
                    ->visible($gold !== null),
                TextEntry::make('gold_version')
                    ->label('Версия')
                    ->state($version)
                    ->visible($gold !== null),
                TextEntry::make('gold_source')
                    ->label('Источник')
                    ->state($source)
                    ->visible($gold !== null),
                TextEntry::make('gold_body')
                    ->hiddenLabel()
                    ->state(self::goldBody($gold))
                    ->visible($gold !== null)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Состояние блока разбора: либо сводка от анализатора, либо ЧЕСТНОЕ сообщение о том, чего
     * не хватает и чем это заводится — пустой блок не показывается
     * никогда.
     *
     * @return array<string, mixed>
     */
    private static function summaryState(AnalysisRun $run): array
    {
        $analysis = self::analysisOfStep($run, AnalysisStep::Extraction);

        if ($analysis === null) {
            return ['message' => $run->screening_disposition === null || $run->screening_disposition === 'extract'
                ? 'Результат разбора ещё не опубликован. Состояние и причины ожидания доступны в workflow.'
                : 'Первичный анализ завершён: '.$run->screening_disposition.'. Разбор не планировался.'];
        }

        if ($analysis->status !== AnalysisStatus::Succeeded) {
            return ['message' => 'Результат разбора не опубликован; состояние работы доступно в workflow.'];
        }

        $documentType = $analysis->document_type === null
            ? null
            : AiDocumentType::tryFrom($analysis->document_type);

        if ($documentType === null) {
            return ['message' => 'У анализа не проставлен тип документа — спросить сводку не у кого.'];
        }

        $analyzer = app(DocumentAnalyzerRegistry::class)->for($documentType);

        if ($analyzer === null) {
            return ['message' => "Для типа «{$documentType->value}» анализатор не зарегистрирован — сводку показать нечем."];
        }

        return self::summaryPayload($analyzer->summarize($analysis));
    }

    /**
     * Плоский payload сводки для generic-рендера: витрина не разбирает структуру результата,
     * она перекладывает пары «подпись → значение» DTO в вёрстку.
     *
     * @return array<string, mixed>
     */
    private static function summaryPayload(AnalysisSummary $summary): array
    {
        $shown = array_slice($summary->metrics, 0, self::MAX_METRICS);

        return [
            'title' => $summary->title,
            'metrics' => array_map(static fn (SummaryMetric $metric): array => [
                'label' => $metric->label,
                'value' => (string) $metric->value,
                'unit' => $metric->unit,
            ], $shown),
            'total' => count($summary->metrics),
            'hidden' => count($summary->metrics) - count($shown),
            'url' => self::detailUrl($summary),
        ];
    }

    /**
     * Ссылка на предметный экран типа: анализатор отдаёт ИМЯ маршрута и параметры,
     * URL строит презентационный слой. Незарегистрированный маршрут — не ошибка показа: ссылки
     * просто нет.
     */
    private static function detailUrl(AnalysisSummary $summary): ?string
    {
        if ($summary->detailRoute === null || ! Route::has($summary->detailRoute)) {
            return null;
        }

        return route($summary->detailRoute, $summary->detailRouteParameters ?? []);
    }

    /**
     * Эталон «по эталону и задаче» — последняя версия записи датасета фикстуры для задачи того
     * шага, результат которого показан: разбор, если он был, иначе первичный анализ.
     */
    private static function gold(Fixture $fixture, AnalysisRun $run): ?DatasetExample
    {
        $task = self::analysisOfStep($run, AnalysisStep::Extraction) === null
            ? AnalysisStep::Screening
            : AnalysisStep::Extraction;

        /** @var DatasetExample|null $example */
        $example = $fixture->datasetExamples()
            ->where('task', $task->value)
            ->orderByDesc('version')
            ->first();

        return $example;
    }

    /**
     * Тело эталона — показ, не правка: JSON как есть, обрезанный по числу строк с явным
     * указанием факта обрезания.
     */
    private static function goldBody(?DatasetExample $gold): ?string
    {
        if ($gold === null) {
            return null;
        }

        $json = (string) json_encode($gold->gold?->toArray() ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $lines = explode("\n", $json);
        $total = count($lines);

        if ($total <= self::MAX_GOLD_LINES) {
            return $json;
        }

        return implode("\n", array_slice($lines, 0, self::MAX_GOLD_LINES))
            ."\n… показаны первые ".self::MAX_GOLD_LINES." строк из {$total}.";
    }

    /**
     * Последний анализ шага в прогоне: у перезапущенного шага их несколько, показывается свежий.
     */
    private static function analysisOfStep(AnalysisRun $run, AnalysisStep $step): ?Analysis
    {
        $analysis = $run->analyses
            ->where('step', $step)
            ->sortByDesc('id')
            ->first();

        return $analysis instanceof Analysis ? $analysis : null;
    }

    private static function confidence(?Analysis $analysis): ?string
    {
        $confidence = $analysis?->storedScreeningResult()?->confidence;

        return $confidence === null ? null : (string) $confidence;
    }

    private static function summary(?Analysis $analysis): ?string
    {
        return $analysis?->storedScreeningResult()?->summary;
    }

    private static function tags(?Analysis $analysis): ?string
    {
        $screening = $analysis?->storedScreeningResult();
        $tags = $screening === null ? [] : $screening->tags;

        if ($tags === []) {
            return null;
        }

        return implode(', ', array_map(static fn (mixed $tag): string => (string) $tag, $tags));
    }

    /**
     * Backend-owned disposition/reason в v2-результате; провайдер не может его подменить.
     */
    private static function decision(?Analysis $analysis): ?string
    {
        $decision = $analysis?->storedScreeningResult()?->decision;

        return $decision === null ? null : "{$decision->disposition->value}/{$decision->reason->value}";
    }
}
