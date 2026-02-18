<?php

declare(strict_types=1);

namespace App\Filament\Components;

use Illuminate\View\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaDownloadLink extends Component
{
    public function __construct(
        public ?Media $file = null,
        public string $label = 'Скачать',
    ) {}

    public function url(): ?string
    {
        $file = $this->file;
        if ($file === null) {
            return null;
        }

        $privateDisk = config('app.filesystems.private_disk_name');
        $isOnPrivateDisk = $file->disk === $privateDisk;

        return $isOnPrivateDisk
            ? route('media.download', [
                'uuid' => $file->uuid,
                'collection_name' => $file->collection_name,
            ])
            : $file->getUrl();
    }

    public function isImage(): bool
    {
        $file = $this->file;
        if ($file === null) {
            return false;
        }

        return str_starts_with($file->mime_type ?? '', 'image/');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return array_merge(parent::data(), [
            'url' => $this->url(),
        ]);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('filament.components.media-download-link');
    }
}
