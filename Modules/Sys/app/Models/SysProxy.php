<?php

namespace Sys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use \Sys\Database\Factories\SysProxyFactory;

class SysProxy extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): SysProxyFactory
    // {
    //     // return SysProxyFactory::new();
    // }
}
