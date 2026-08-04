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
 * Загрузка идёт через `FixtureUploader` (D133), НЕ через дефолтный `Model::create($data)`:
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
        /** @var TemporaryUploadedFile $file */
        $file = $data['source'];

        try {
            return app(FixtureUploader::class)->upload(
                file: $file,
                title: (string) $data['title'],
                documentType: AiDocumentType::from((string) $data['document_type']),
                ownerDocumentType: AiDocumentType::from((string) $data['owner_document_type']),
                typeProfile: (array) ($data['type_profile'] ?? []),
                notes: $data['notes'] !== '' ? ($data['notes'] ?? null) : null,
                quirks: (array) ($data['quirks'] ?? []),
                generator: ($data['generator'] ?? '') !== '' ? $data['generator'] : null,
                clusterId: ($data['cluster_id'] ?? '') !== '' ? $data['cluster_id'] : null,
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
}
