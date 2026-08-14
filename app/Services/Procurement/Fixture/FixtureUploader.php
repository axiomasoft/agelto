<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Exceptions\Procurement\Ai\FixtureAlreadyUploaded;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Загрузка НОВОГО эталона из формы витрины (`FixtureResource`, P5.1, D133) — единственный сервис
 * этого шага, ЕДИНСТВЕННЫЙ построитель кода и пути остаётся модель `Fixture`
 * (Implementation Rule 2). Порядок обязателен: сначала диск, потом запись (Implementation Rule 3)
 * — запись без файла делает реестр лжецом, файл без записи виден `estimate:fixtures:verify` как
 * orphan. Файл читается и пишется ПОТОКОМ (`hash_file`/`readStream`): реальный эталон корпуса — до
 * сотен мегабайт, `file_get_contents` убивает воркер.
 */
final readonly class FixtureUploader
{
    public function __construct(
        private FixtureStoreRepository $fixtures,
        private FileLocationResolver $locationResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $typeProfile
     * @param  list<string>  $quirks
     *
     * @throws FixtureAlreadyUploaded
     */
    public function upload(
        TemporaryUploadedFile $file,
        string $title,
        AiDocumentType $documentType,
        AiDocumentType $ownerDocumentType,
        array $typeProfile,
        ?string $notes = null,
        array $quirks = [],
        ?string $generator = null,
        ?string $clusterId = null,
    ): Fixture {
        $sha256 = (string) hash_file('sha256', $file->getRealPath());

        $existing = $this->fixtures->findBySha256($sha256);

        if ($existing !== null) {
            throw new FixtureAlreadyUploaded($existing->code);
        }

        $sourceFormat = strtolower($file->getClientOriginalExtension());
        $classValue = $typeProfile['class'] ?? null;
        $formValue = $typeProfile['form'] ?? null;

        if (! is_string($classValue) || ! is_string($formValue)) {
            throw new \UnexpectedValueException('Fixture type profile must contain string class and form values.');
        }

        $class = FixtureClass::from($classValue);
        $form = $formValue;

        $code = Fixture::nextCodeFor($class, $sourceFormat, $form);
        $storagePath = Fixture::storagePathFor($code, $sourceFormat);
        $disk = $this->locationResolver->currentFixturesDisk();

        $stream = $file->readStream();

        if (! is_resource($stream)) {
            throw new \UnexpectedValueException('Fixture upload stream is unavailable.');
        }

        Storage::disk($disk)->put($storagePath, $stream);

        return $this->fixtures->storeOrUpdate($code, [
            'source_format' => $sourceFormat,
            'storage_path' => $storagePath,
            'disk' => $disk,
            'sha256' => $sha256,
            'size_bytes' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'original_filename' => $file->getClientOriginalName(),
            'source_path' => $storagePath,
            'generator' => $generator,
            'cluster_id' => $clusterId,
            'document_type' => $documentType,
            'owner_document_type' => $ownerDocumentType,
            'title' => $title,
            'quirks' => $quirks,
            'type_profile' => $typeProfile,
            'notes' => $notes,
        ]);
    }
}
