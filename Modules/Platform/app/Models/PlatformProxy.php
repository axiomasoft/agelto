<?php

namespace Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlatformProxy extends Model
{
    use HasFactory;

    protected $table = 'plt_platform_proxies';

    protected $fillable = [];
}
