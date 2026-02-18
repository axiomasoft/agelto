<?php

declare(strict_types=1);

namespace Spatie\MediaLibraryPro\Models;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibraryPro\Exceptions\CouldNotAddUpload;
use Spatie\MediaLibraryPro\Exceptions\TemporaryUploadDoesNotBelongToCurrentSession;

final class TemporaryUpload extends Model implements HasMedia
{
    use InteractsWithMedia;
    use MassPrunable;

    public static ?Closure $manipulatePreview = null;

    public static ?string $disk = null;

    protected $guarded = [];

    public static function previewManipulation(Closure $closure): void
    {
        self::$manipulatePreview = $closure;
    }

    public static function getDiskName(): string
    {
        return self::$disk ?? config('media-library.disk_name');
    }

    public static function findByMediaUuid(?string $mediaUuid): ?self
    {
        $mediaModelClass = config('media-library.media_model');

        /** @var Media $media */
        $media = $mediaModelClass::query()
            ->where('uuid', $mediaUuid)
            ->first();

        if (! $media) {
            return null;
        }

        $temporaryUpload = $media->model;

        if (! $temporaryUpload instanceof self) {
            return null;
        }

        return $temporaryUpload;
    }

    public static function findByMediaUuidInCurrentSession(?string $mediaUuid): ?self
    {
        if (! $temporaryUpload = self::findByMediaUuid($mediaUuid)) {
            return null;
        }

        if (config('media-library.enable_temporary_uploads_session_affinity', true)) {
            if ($temporaryUpload->session_id !== session()->getId()) {
                return null;
            }
        }

        return $temporaryUpload;
    }

    public static function createForFile(
        UploadedFile $file,
        string $sessionId,
        string $uuid,
        string $name
    ): self {
        /** @var TemporaryUpload $temporaryUpload */
        $temporaryUpload = self::create([
            'session_id' => $sessionId,
        ]);

        if (self::findByMediaUuid($uuid)) {
            throw CouldNotAddUpload::uuidAlreadyExists();
        }

        $temporaryUpload
            ->addMedia($file)
            ->setName($name)
            ->withProperties(['uuid' => $uuid])
            ->toMediaCollection('default', self::getDiskName());

        return $temporaryUpload->fresh();
    }

    public static function createForRemoteFile(
        string $file,
        string $sessionId,
        string $uuid,
        string $name,
        string $diskName
    ): self {
        /** @var TemporaryUpload $temporaryUpload */
        $temporaryUpload = self::create([
            'session_id' => $sessionId,
        ]);

        if (self::findByMediaUuid($uuid)) {
            throw CouldNotAddUpload::uuidAlreadyExists();
        }

        $temporaryUpload
            ->addMediaFromDisk($file, $diskName)
            ->setName($name)
            ->usingFileName($name)
            ->withProperties(['uuid' => $uuid])
            ->toMediaCollection('default', self::getDiskName());

        return $temporaryUpload->fresh();
    }

    public function scopeOld(Builder $builder): void
    {
        $builder->where('created_at', '<=', Carbon::now()->subDay()->toDateTimeString());
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if (! config('media-library.generate_thumbnails_for_temporary_uploads')) {
            return;
        }

        $conversion = $this
            ->addMediaConversion('preview')
            ->nonQueued();

        $previewManipulation = $this->getPreviewManipulation();

        $previewManipulation($conversion);
    }

    public function moveMedia(HasMedia $toModel, string $collectionName, string $diskName, string $fileName): Media
    {
        if (config('media-library.enable_temporary_uploads_session_affinity', true)) {
            if ($this->session_id !== session()->getId()) {
                throw TemporaryUploadDoesNotBelongToCurrentSession::create();
            }
        }

        $media = $this->getFirstMedia();

        $temporaryUploadModel = $media->model;
        $uuid = $media->uuid;

        $newMedia = $media->move($toModel, $collectionName, $diskName, $fileName);

        $temporaryUploadModel->delete();

        $newMedia->update(compact('uuid'));

        return $newMedia;
    }

    public function prunable(): Builder
    {
        return self::query()->old();
    }

    protected function getPreviewManipulation(): Closure
    {
        return self::$manipulatePreview ?? function (Conversion $conversion) {
            $conversion->fit(Manipulations::FIT_CROP, 300, 300);
        };
    }
}
