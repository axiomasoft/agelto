<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Fixture\ItemOutcome;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\DatasetExampleStoreRepository;
use App\Services\Procurement\Gold\GoldValidator;
use Throwable;

/**
 * Ветвление screening/extraction при импорте ОДНОГО эталонного примера (`estimate:gold:import`, P5.5).
 */
final readonly class GoldExampleImporter
{
    public function __construct(
        private GoldValidator $goldValidator,
        private DatasetExampleStoreRepository $examples,
    ) {}

    public function import(DocumentVersion $version, Fixture $fixture, string $task, string $path, bool $isScreening): ItemOutcome
    {
        $decoded = json_decode((string) file_get_contents($path), associative: true);

        if (! is_array($decoded)) {
            return new ItemOutcome($fixture->code, 'rejected', "{$path} не парсится как JSON-объект");
        }

        $payload = $this->stringKeyedArray($decoded);

        if ($isScreening) {
            try {
                ScreeningResult::validateAndCreate($payload);
            } catch (Throwable $e) {
                return new ItemOutcome($fixture->code, 'rejected', "screening-gold не прошёл валидацию: {$e->getMessage()}");
            }
        } else {
            $errors = $this->goldValidator->validate($payload);

            if ($errors !== []) {
                return new ItemOutcome($fixture->code, 'rejected', 'extraction-gold не прошёл валидацию: '.implode('; ', $errors));
            }
        }

        $this->examples->storeOrUpdate($version->id, $task, 1, [
            'fixture_id' => $fixture->id,
            'document_type' => $fixture->document_type->value,
            'gold' => $decoded,
            'source' => 'deterministic',
        ]);

        return new ItemOutcome($fixture->code, 'imported');
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
