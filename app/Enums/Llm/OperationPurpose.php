<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum OperationPurpose: string
{
    case Generate = 'generate';
    case CacheCreate = 'cache_create';
    case CacheRefresh = 'cache_refresh';
    case CacheStorage = 'cache_storage';
    case FileUpload = 'file_upload';
    case Lookup = 'lookup';
}
