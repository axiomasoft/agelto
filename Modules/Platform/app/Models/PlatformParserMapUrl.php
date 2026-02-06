<?php

namespace Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Platform\Database\Factories\PlatformParserMapUrlFactory;

class PlatformParserMapUrl extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PlatformParserMapUrlFactory
    // {
    //     // return PlatformParserMapUrlFactory::new();
    // }
}
