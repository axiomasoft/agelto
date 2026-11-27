<?php

declare(strict_types=1);

namespace App\Models\Procurement\Proxyma;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table(name: 'prc_network_cutover_journals', keyType: 'string', incrementing: false, timestamps: false)]
final class CutoverJournal extends Model {}
