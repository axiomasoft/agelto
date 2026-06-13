<?php

declare(strict_types=1);

namespace App\Process\DocumentAnalysis;

use App\Dto\Prompt\Step;
use App\Enums\AnalysisStatus;
use App\Enums\LogOutput;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentAnalysis\Schema\DocumentType;
use App\Process\DocumentAnalysis\Schema\Responses\PrimaryAnalysisResponse;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use App\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;
use App\Repositories\Prompt\PromptRuleRepository;
use App\Services\AppLogger;
use App\Services\GeminiService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class PrimaryAnalysisProcessor
{
    private const STEP = Step::PrimaryAnalysis;

    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo,
        private readonly AppLogger $logger,
    ) {}

    /**
     * @param  array<int, mixed>  $args  Доп. аргументы для initial() (например, ['url'] — поле ссылки модели).
     */
    public function run(Media|Model|string $data, array $args = []): ?DocumentAnalysis
    {
        // Сразу создаём запись (статус Preparing), затем цепочка: подготовка → анализ.
        $analysis = $this->initial($data, $args);

        Bus::chain([
            new PrepareDocumentJob($analysis->id),
            new PrimaryAnalysisJob($analysis->id),
        ])->dispatch();

        return $analysis;
    }

    public function analysis(DocumentAnalysis $analysis): void
    {
        $analysis->transitionStatus(status: AnalysisStatus::PrimaryAnalyzing, message: 'Начало первичного анализа');

        $this->logger->info('Начало первичного анализа '.$analysis->id, LogOutput::Console);

        $fileUri = $this->gemini->ensureFileInCloud($analysis);

        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep(self::STEP);

        $prompt = 'Выполни первичный анализ согласно правилам.';

        $response = $this->gemini->generateJson(
            prompt: $prompt,
            data: $fileUri,
            systemInstruction: $systemInstructions
        );

        // 1. Оборачиваем в схему и валидируем
        // Если используешь Spatie Laravel Data:
        $responseObject = PrimaryAnalysisResponse::from($response);

        // 2. Дополнительная проверка бизнес-логики (если нужна)
        if (! $responseObject->success) {
            // обработка логики "пустого" результата
        }

        $analysis->transitionStatus(status: AnalysisStatus::PrimaryAnalyzed, message: 'Первичный анализ выполнен');

        DB::transaction(function () use ($analysis, $responseObject, $prompt, $systemInstructions): void {
            $analysis->responses()->create([
                'step' => self::STEP,
                'success' => $responseObject->success,
                'message' => $responseObject->message ?? null,
                'data' => $responseObject->data->toArray(),
                'prompt' => $prompt,
                'rules_hash' => sha1($systemInstructions),
            ]);

            if ($responseObject->data->document_type !== DocumentType::Estimate) {
                $analysis->transitionStatus(status: AnalysisStatus::PrimaryIgnored, message: 'Документ не является сметой');
            }
        });

        // Статус EstimateAnalyzing ставит уже сам job в воркере — здесь только диспатч.
        if ($responseObject->data->document_type === DocumentType::Estimate) {
            EstimateAnalysisJob::dispatch(documentAnalysisId: $analysis->id)->afterCommit();
        }
    }

    public function initial(Media|Model|string $data, $args = []): DocumentAnalysis
    {
        if ($data instanceof Media) {
            return $this->fromModel($data, ...$args);
        }
        if ($data instanceof Model) {
            return $this->fromModel($data, ...$args);
        }
        if (is_string($data)) {
            return $this->fromUrl($data);
        }

        throw new Exception('Некорректный тип данных для метода initial');
    }

    public function fromModel(Model $model, ?string $urlField = null, ?string $collectionName = null): DocumentAnalysis
    {
        // Объект Media
        if ($model instanceof Media) {
            if (! $collectionName) {
                throw new Exception('Не указано имя коллекции для Media');
            }

            return $this->createAnalysis(model: $model, source: $collectionName);
        }
        // Поле ссылки в модели
        if ($urlField && filter_var($model->{$urlField}, FILTER_VALIDATE_URL)) {
            $url = (string) $model->{$urlField};

            return $this->createAnalysis(model: $model, source: $url);
        }
        if ($collectionName) {
            return $this->createAnalysis(model: $model, source: $collectionName);
        }

        throw new Exception('Некорректный тип данных для PrimaryAnalysisProcessor');
    }

    public function fromUrl(string $url): DocumentAnalysis
    {
        return DocumentAnalysis::query()->firstOrCreate(
            attributes: ['source' => $url],
            values: ['status' => AnalysisStatus::Preparing->value],
        );
    }

    public function createAnalysis(Model|Media $model, ?string $source = null): DocumentAnalysis
    {
        return DocumentAnalysis::query()->firstOrCreate(
            attributes: [
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'source' => $source,
            ],
            values: ['status' => AnalysisStatus::Preparing->value],
        );
    }
}
