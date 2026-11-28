<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Только имя исторической таблицы для migration 2026_07_04_100040.
 * Runtime не обращается к этому carrier; архив читается явным history adapter.
 */
#[Table(name: 'prc_proxies')]
final class Proxy extends Model {}
