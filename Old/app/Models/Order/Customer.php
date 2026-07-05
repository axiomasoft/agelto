<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Customer extends Model
{
    protected $table = 'ord_customers';

    protected $fillable = [
        'full_name',
        'short_name',
        'inn',
        'kpp',
        'ogrn',
        'address',
        'phone',
        'email',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
