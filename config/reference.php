<?php

declare(strict_types=1);

use App\Models\Reference\Kbk;
use App\Models\Reference\Ktru;
use App\Models\Reference\Okpd2;
use App\Models\Reference\Okved2;

return [
    /*
    |--------------------------------------------------------------------------
    | Классификаторы
    |--------------------------------------------------------------------------
    |
    | Карта ключ команды `ref:load-classifiers {classifier}` → модель + путь до
    | официальной выгрузки (Росстат/Минфин). `source_path` не задан по умолчанию —
    | production-наполнение вне кода (D16, операторская/data-задача); команда
    | пропускает классификатор без пути с предупреждением, не падает.
    |
    */
    'classifiers' => [
        'okpd2' => [
            'model' => Okpd2::class,
            'source_path' => env('REF_OKPD2_SOURCE_PATH'),
        ],
        'ktru' => [
            'model' => Ktru::class,
            'source_path' => env('REF_KTRU_SOURCE_PATH'),
        ],
        'okved2' => [
            'model' => Okved2::class,
            'source_path' => env('REF_OKVED2_SOURCE_PATH'),
        ],
        'kbk' => [
            'model' => Kbk::class,
            'source_path' => env('REF_KBK_SOURCE_PATH'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Импорт
    |--------------------------------------------------------------------------
    |
    | Размер чанка upsert-а строк выгрузки — не грузить весь файл в память.
    |
    */
    'import_chunk_size' => 500,
];
