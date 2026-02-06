<?php

namespace Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Platform\Database\Factories\PlatformParserAnalyticsFactory;

class PlatformParserAnalytics extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PlatformParserAnalyticsFactory
    // {
    //     // return PlatformParserAnalyticsFactory::new();
    // }
}
