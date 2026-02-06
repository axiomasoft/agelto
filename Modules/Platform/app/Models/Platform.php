<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Platform\Database\Factories\PlatformFactory;

class Platform extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
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
