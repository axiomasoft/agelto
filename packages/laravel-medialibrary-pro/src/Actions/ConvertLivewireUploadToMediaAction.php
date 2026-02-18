<?php

declare(strict_types=1);

namespace Spatie\MediaLibraryPro\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibraryPro\Models\TemporaryUpload;

final class ConvertLivewireUploadToMediaAction
{
    public function execute(TemporaryUploadedFile $temporaryUploadedFile): Media
    {
        return $this->isLocalTemporaryDisk()
            ? $this->createFromLocalLivewireUpload($temporaryUploadedFile)
            : $this->createFromRemoteLivewireUpload($temporaryUploadedFile);
    }

    private function isLocalTemporaryDisk(): bool
    {
        // See \Livewire\FileUploadConfiguration::isUsingS3()

        $diskBeforeTestFake = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        return config('filesystems.disks.'.mb_strtolower($diskBeforeTestFake).'.driver') === 'local';
    }

    private function createFromLocalLivewireUpload(TemporaryUploadedFile $livewireUpload): Media
    {
        $uploadedFile = new UploadedFile($livewireUpload->path(), $livewireUpload->getClientOriginalName());

        /** @var class-string<TemporaryUpload> $temporaryUploadModelClass */
        $temporaryUploadModelClass = config('media-library.temporary_upload_model');

        $temporaryUpload = $temporaryUploadModelClass::createForFile(
            $uploadedFile,
            session()->getId(),
            (string) Str::uuid(),
            $livewireUpload->getClientOriginalName()
        );

        return $temporaryUpload->getFirstMedia();
    }

    private function createFromRemoteLivewireUpload(TemporaryUploadedFile $livewireUpload): Media
    {
        /** @var class-string<TemporaryUpload> $temporaryUploadModelClass */
        $temporaryUploadModelClass = config('media-library.temporary_upload_model');

        $livewireDisk = config('livewire.temporary_file_upload.disk', 's3');

        $livewireDirectory = FileUploadConfiguration::directory();
        $remotePath = Str::of($livewireDirectory)->start('/')->finish('/').$livewireUpload->getFilename();

        $temporaryUpload = $temporaryUploadModelClass::createForRemoteFile(
            $remotePath,
            session()->getId(),
            (string) Str::uuid(),
            $livewireUpload->getClientOriginalName(),
            $livewireDisk
        );

        return $temporaryUpload->getFirstMedia();
    }
}
