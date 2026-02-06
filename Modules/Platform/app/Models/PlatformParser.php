<?php

namespace Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Platform\Database\Factories\PlatformParserFactory;

class PlatformParser extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PlatformParserFactory
    // {
    //     // return PlatformParserFactory::new();
    // }
}
