<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/** Catalog includes pending writes so interrupted storage writes are eligible for retention. */
#[Table(name: 'prc_raw_artifacts', keyType: 'string')]
#[WithoutIncrementing]
#[WithoutTimestamps]
final class RawArtifact extends Model {}
