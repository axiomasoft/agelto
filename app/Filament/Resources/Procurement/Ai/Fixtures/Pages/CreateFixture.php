<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Pages;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Exceptions\Procurement\Ai\FixtureAlreadyUploaded;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Fixture\FixtureUploader;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Загрузка идёт через `FixtureUploader`, НЕ через дефолтный `Model::create($data)`:
 * код и путь эталона считает модель `Fixture`, дедуп по sha256 — сервис. Дубль отклоняется
 * `FixtureAlreadyUploaded` — показывается уведомлением, форма остаётся на странице (`Halt`).
 */
class CreateFixture extends CreateRecord
{
    protected static string $resource = FixtureResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $file = $data['source'] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            throw new \InvalidArgumentException('Нужен загружаемый файл эталона.');
        }

        try {
            return app(FixtureUploader::class)->upload(
                file: $file,
                title: $this->requiredString($data['title'] ?? null, 'title'),
                documentType: AiDocumentType::from($this->requiredString($data['document_type'] ?? null, 'document_type')),
                ownerDocumentType: AiDocumentType::from($this->requiredString($data['owner_document_type'] ?? null, 'owner_document_type')),
                typeProfile: $this->object($data['type_profile'] ?? []),
                notes: $this->nullableString($data['notes'] ?? null, 'notes'),
                quirks: $this->stringList($data['quirks'] ?? []),
                generator: $this->nullableString($data['generator'] ?? null, 'generator'),
                clusterId: $this->nullableString($data['cluster_id'] ?? null, 'cluster_id'),
            );
        } catch (FixtureAlreadyUploaded $e) {
            Notification::make()
                ->title('Файл уже загружен')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        /** @var Fixture $record */
        $record = $this->getRecord();

        return FixtureResource::getUrl('view', ['record' => $record]);
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("{$field} должен быть непустой строкой.");
        }

        return $value;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException("{$field} должен быть строкой.");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('type_profile должен быть объектом.');
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('type_profile содержит нестроковый ключ.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('quirks должен быть списком строк.');
        }

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new \InvalidArgumentException('quirks должен содержать только строки.');
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
