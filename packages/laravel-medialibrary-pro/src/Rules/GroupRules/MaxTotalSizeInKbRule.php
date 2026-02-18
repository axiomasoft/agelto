<?php

declare(strict_types=1);

namespace Spatie\MediaLibraryPro\Rules\GroupRules;

use Illuminate\Contracts\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\File;

final class MaxTotalSizeInKbRule implements Rule
{
    private int $actualTotalSizeInKb;

    public function __construct(private int $maxTotalSizeInKb) {}

    public function passes($attribute, $uploadedItems): bool
    {
        $uuids = collect($uploadedItems)
            ->map(fn (array $uploadedItemAttributes) => $uploadedItemAttributes['uuid'])
            ->toArray();

        $media = Media::findWithTemporaryUploadInCurrentSession($uuids);

        $this->actualTotalSizeInKb = $media->totalSizeInBytes();

        return $this->actualTotalSizeInKb <= ($this->maxTotalSizeInKb * 1024);
    }

    public function message(): string
    {
        return __('media-library::validation.total_upload_size_too_high', [
            'max' => File::getHumanReadableSize($this->maxTotalSizeInKb * 1024),
            'maxInKb' => $this->maxTotalSizeInKb,
            'actual' => File::getHumanReadableSize($this->actualTotalSizeInKb * 1024),
            'actualInKb' => $this->actualTotalSizeInKb,
        ]);
    }
}
