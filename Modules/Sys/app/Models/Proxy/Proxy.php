<?php

declare(strict_types=1);

namespace Sys\Models\Proxy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Proxy extends Model
{
    protected $table = 'sys_proxies';

    protected $fillable = [
        'service_id',
        'username',
        'password',
        'ip_address',
        'port',
        'targets',
        'is_active',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    protected function casts(): array
    {
        return [
            'targets' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
