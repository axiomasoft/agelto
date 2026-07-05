<?php

declare(strict_types=1);

namespace Spatie\MediaLibraryPro\Rules\ItemRules;

use Illuminate\Contracts\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

abstract class MediaItemRule implements Rule
{
    public $value;

    abstract public function validateMediaItem(): bool;

    final public function passes($attribute, $value)
    {
        $this->value = $value;

        return $this->validateMediaItem();
    }

    final public function getTemporaryUploadMedia(): ?Media
    {
        $temporaryUploadModelClass = config('media-library.temporary_upload_model');

        $temporaryUpload = $temporaryUploadModelClass::findByMediaUuidInCurrentSession($this->value['uuid']);

        if (! $temporaryUpload) {
            return null;
        }

        return $temporaryUpload->getFirstMedia();
    }
}
