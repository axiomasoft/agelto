<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis;

use App\Enums\LogOutput;
use App\Services\AppLogger;
use App\Services\GeminiService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Platform\Dto\PromtRule\Step;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use Platform\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use Platform\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;
use Platform\Repositories\Promt\PromptRuleRepository;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Platform\Process\DocumentAnalysis\Schema\Responses\PrimaryAnalysisResponse;
use Platform\Enums\AnalysisStatus;
use Platform\Process\DocumentAnalysis\Schema\DocumentType;
use Illuminate\Support\Facades\DB;

final class PrimaryAnalysisProcessor
{
    private const STEP = Step::PrimaryAnalysis;

    public function __construct(
        private GeminiService $gemini,
        private PromptRuleRepository $promptRepo,
        private readonly AppLogger $logger,
    ) {}

    public function run(Media|Model|string $data): ?DocumentAnalysis
    {
        // 1. Мгновенно создаем запись в БД (статус 'initial')
        $analysis = $this->initial($data);

        // 2. Запускаем цепочку: Сначала ТЯЖЕЛАЯ ПОДГОТОВКА, потом АНАЛИЗ
        Bus::chain([
            new PrepareDocumentJob($analysis->id), // Здесь будет Excel -> PDF и т.д.
            new PrimaryAnalysisJob($analysis->id),      // Здесь работа с Gemini
        ])->dispatch();

        return $analysis;
    }

    public function analysis(DocumentAnalysis $analysis): void
    {
        $analysis->update(['status' => AnalysisStatus::PrimaryAnalyzing]);

        $this->logger->info('Начало первичного анализа ' . $analysis->id, LogOutput::Console);

        $fileUri = $this->gemini->ensureFileInCloud($analysis);

        $systemInstructions = $this->promptRepo->getSystemInstructionsForStep(self::STEP);

        $promt = "Выполни первичный анализ согласно правилам.";

        $response = $this->gemini->generateJson(
            prompt: $promt,
            data: $fileUri,
            systemInstruction: $systemInstructions
        );

        // 1. Оборачиваем в схему и валидируем
        // Если используешь Spatie Laravel Data:
        $responseObject = PrimaryAnalysisResponse::from($response);

        // 2. Дополнительная проверка бизнес-логики (если нужна)
        if (!$responseObject->success) {
            // обработка логики "пустого" результата
        }

        $analysis->update(['status' => AnalysisStatus::PrimaryAnalyzed]);

        DB::transaction(function () use ($analysis, $responseObject, $promt, $systemInstructions) {

            // 3. Сохраняем в базу, используя свойства объекта
            $analysis->responses()->create([
                'step' => self::STEP,
                'success' => $responseObject->success,
                'message' => $responseObject->message ?? null,
                'data' => $responseObject->data->toArray(),
                'promt' => $promt,
                'rules_hash' => sha1($systemInstructions),
            ]);

            if ($responseObject->data->document_type === DocumentType::Estimate) {
                $analysis->update(['status' => AnalysisStatus::EstimateAnalyzing]);
                EstimateAnalysisJob::dispatch(documentAnalysisId: $analysis->id);
            } else {
                $analysis->update(['status' => AnalysisStatus::PrimaryIgnored]);
            }
        });
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
        return DocumentAnalysis::query()->firstOrCreate([
            'source' => $url,
        ]);
    }

    public function createAnalysis(Model|Media $model, ?string $source = null): DocumentAnalysis
    {
        return DocumentAnalysis::query()->firstOrCreate([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'source' => $source,
        ]);
    }
}
