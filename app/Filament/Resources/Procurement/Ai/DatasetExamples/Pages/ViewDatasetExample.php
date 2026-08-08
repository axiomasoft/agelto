<?php

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages;

use App\Dto\Procurement\Gold\GoldDocumentView;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\GoldContentSchema;
use App\Models\Procurement\Ai\DatasetExample;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

class ViewDatasetExample extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = DatasetExampleResource::class;

    protected string $view = 'filament.resources.dataset-examples.pages.view-dataset-example';

    private bool $documentResolved = false;

    private ?GoldDocumentView $document = null;

    private ?string $readError = null;

    public function getTitle(): string
    {
        return 'Эталонный ответ';
    }

    public function table(Table $table): Table
    {
        $document = $this->goldDocument();

        return $document === null
            ? $table->records(fn (): array => [])
            : GoldContentSchema::table($table, $document);
    }

    public function goldDocument(): ?GoldDocumentView
    {
        if ($this->documentResolved) {
            return $this->document;
        }

        $this->documentResolved = true;
        $gold = $this->datasetExample()->gold;

        if ($gold === null || $gold->isEmpty()) {
            return null;
        }

        try {
            return $this->document = GoldDocumentView::fromPayload($gold->all());
        } catch (ValidationException|CannotCreateData $exception) {
            $this->readError = "Gold-снапшот не соответствует контракту: {$exception->getMessage()}";

            return null;
        }
    }

    public function emptyStateMessage(): string
    {
        if ($this->readError !== null) {
            return $this->readError;
        }

        $example = $this->datasetExample();

        if (filled($example->gold_ref)) {
            return "Тело снимка хранится во внешнем хранилище: {$example->gold_ref}. Витрина его не загружает.";
        }

        return 'Содержимое gold-снапшота отсутствует.';
    }

    private function datasetExample(): DatasetExample
    {
        /** @var DatasetExample $example */
        $example = $this->getRecord();

        return $example;
    }
}
