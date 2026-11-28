<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Document;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic persisted fixture metadata only. Runtime writers verify bytes; factories do not claim object I/O. */
final class CanonicalArtifactFactory
{
    /** @param array<string,mixed> $attributes */
    public static function blob(array $attributes): string
    {
        $disk = $attributes['disk'];
        $path = $attributes['storage_path'];
        $hash = $attributes['content_hash'];
        $size = $attributes['size_bytes'];
        $existing = DB::table('prc_document_blobs')->where('disk', $disk)->where('object_key', $path)->first();
        if ($existing !== null) {
            return $existing->id;
        }
        $domain = (string) Str::uuid();
        DB::table('prc_storage_domains')->insert(['id' => $domain, 'domain_key' => 'fixture:'.$domain,
            'capacity_bytes' => max(1, $size), 'high_watermark_bytes' => max(1, $size), 'accounted_durable_bytes' => $size, 'policy_version' => 1]);
        $id = (string) Str::uuid();
        DB::table('prc_document_blobs')->insert(['id' => $id, 'storage_domain_id' => $domain, 'content_hash' => '\\x'.$hash,
            'disk' => $disk, 'object_key' => $path, 'size_bytes' => $size, 'verified_at' => now()]);

        return $id;
    }
}
