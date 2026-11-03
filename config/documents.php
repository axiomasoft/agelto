<?php

declare(strict_types=1);

return [
    'format_tools' => [
        'ocr_seconds' => 150,
        'text_layer_probe_seconds' => 30,
        'tesseract_version_seconds' => 10,
        'pdf_page_count_seconds' => 10,
        'persistence_seconds' => 30,
        'pdf_slice_seconds' => 60,
    ],
    // Canonical storage addresses; immutable rows retain their recorded disk.
    'disk' => 's3',
    'fallback_disks' => [],
    'allowed_hosts' => ['zakupki.gov.ru'],
    'signature_bytes' => 8192,
    'inspection_max_bytes' => 104857600,
    'temporary_stale_seconds' => 86400,

    'storage' => [
        'documents.originals' => ['capacity_bytes' => 274877906944, 'high_watermark_bytes' => 219902325555],
        'documents.temporary' => ['capacity_bytes' => 17179869184, 'high_watermark_bytes' => 13743895347],
    ],
    'prepare' => [
        'max_ocr_pages' => 300,
        'chain_seconds' => 200,
        'setup_seconds' => 30,
        'batch_seconds' => 150,
        'persistence_seconds' => 20,
        'reserve_seconds' => 30,
        'tmp_bytes' => 1073741824,
        'artifact_bytes' => 8388608,
        'quality_threshold' => 0.10,
        'allow_degraded_representation' => false,
        'languages' => 'rus+eng',
        'tesseract_timeout_seconds' => 60,
        'tools_version' => 'qpdf version 12.2.0 16.7.0+dfsg1 tesseract 5.5.0 pdftotext version 25.03.0 pdfinfo version 25.03.0',
        'durable_domain' => 'documents.originals',
        'temporary_domain' => 'documents.temporary',
        'disk' => 'local',
        'temporary_disk' => 'local',
    ],
    'download' => [
        'max_bytes' => 268435456,
        'revalidation_seconds' => 2592000,
        'durable_domain' => 'documents.originals',
        'temporary_domain' => 'documents.temporary',
        'disk' => 'local',
        'temporary_disk' => 'local',
    ],
];
