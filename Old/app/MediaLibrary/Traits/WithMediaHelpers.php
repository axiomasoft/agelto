<?php

declare(strict_types=1);

namespace App\MediaLibrary\Traits;

trait WithMediaHelpers
{
    public function syncFileToCollection($file, $collection): static
    {
        if (is_array($file)) {
            $this->syncFromMediaLibraryRequest($file)->toMediaCollection(collection: $collection);
        } elseif ($file === null) {
            $this->clearMediaCollection(collection: $collection);
        }

        return $this;
    }
}
