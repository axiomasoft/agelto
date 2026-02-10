<?php

declare(strict_types=1);

namespace Sys\Models\Proxy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Service extends Model
{
    protected $table = 'sys_proxity_services';

    protected $fillable = [
        'name',
        'code',
    ];

    public function proxies(): HasMany
    {
        return $this->hasMany(Proxy::class, 'service_id');
    }
}
