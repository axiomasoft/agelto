<?php

namespace Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Platform\Database\Factories\PlatformFactory;

class Platform extends Model
{
    use HasFactory;

    protected $table = 'plt_platforms';

    protected $fillable = [
        'name',
        'code',
        'url',
    ];

    // protected static function newFactory(): PlatformFactory
    // {
    //     // return PlatformFactory::new();
    // }
}
