<?php

declare(strict_types=1);

namespace Platform\Models\Parser;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Parser extends Model
{
    use HasFactory;

    protected $table = 'plt_parsers';

    protected $fillable = [];
}
