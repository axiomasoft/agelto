<?php

declare(strict_types=1);

namespace App\MediaLibrary\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator as IPathGenerator;

final class PathGenerator implements IPathGenerator
{
    /*
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    /*
     * Get the path for conversions of the given media, relative to the root storage path.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/c/';
    }

    /*
     * Get the path for responsive images of the given media, relative to the root storage path.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/rsp/';
    }

    /*
     * Get a unique base path for the given media.
     */
    private function getBasePath(Media $media): string
    {
        $prefix = 'default';
        $postfix = '';
        $key = $media->model_id;

        if (property_exists($media->model_type, 'mediaPath')) {
            $class = new $media->model_type;
            $prefix = $class->mediaPath;
        }

        return
            $prefix.'/'.
            hash('ripemd160', $media->model_type.'-'.$key).'/'.
            $media->collection_name.
            (! empty($postfix) ? '/'.$postfix : '');
    }
}
