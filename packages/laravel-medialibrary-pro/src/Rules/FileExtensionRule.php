<?php

declare(strict_types=1);

namespace Spatie\MediaLibraryPro\Rules;

use Illuminate\Contracts\Validation\Rule;

final class FileExtensionRule implements Rule
{
    private array $validExtensions = [];

    public function __construct(array $validExtensions = [])
    {
        $this->validExtensions = array_map(
            fn (string $extension) => mb_strtolower($extension),
            $validExtensions,
        );
    }

    /**
     * @param  string  $attribute
     * @param  \Illuminate\Http\UploadedFile  $value
     */
    public function passes($attribute, $value): bool
    {
        return in_array(
            mb_strtolower($value->getClientOriginalExtension()),
            $this->validExtensions,
            strict: true,
        );
    }

    public function message(): string
    {
        return trans('media-library::validation.mime', [
            'mimes' => implode(', ', $this->validExtensions),
        ]);
    }
}
