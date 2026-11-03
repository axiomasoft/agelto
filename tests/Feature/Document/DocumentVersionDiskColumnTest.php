<?php

declare(strict_types=1);

use App\Models\Procurement\Document\DocumentVersion;

test('колонка диска версии документа пишется и читается', function () {
    $materialized = DocumentVersion::factory()->create();

    expect($materialized->refresh()->disk)->toBe(config('documents.disk'));

    $onDisk = DocumentVersion::factory()->onDisk('s3-gen2')->create();

    expect($onDisk->refresh()->disk)->toBe('s3-gen2');
});
