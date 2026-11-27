<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Persistence uses explicit typed repositories; no model mutation API. */
#[Table(name: 'prc_storage_reservations')]
final class StorageReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
