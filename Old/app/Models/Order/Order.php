<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Order extends Model
{
    protected $table = 'ord_orders';

    protected $fillable = [
        'reg_number',
        'edition_number',
        'name',
        'status',
        'customer_id',
        'platform_id',
        'initial_price',
        'currency',
        'order_published_at',
        'order_updated_at',
        'order_submission_start_at',
        'order_submission_end_at',
        'order_scoring_at',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function purchaseObjects(): HasMany
    {
        return $this->hasMany(PurchaseObject::class);
    }

    protected function casts(): array
    {
        return [
            'edition_number' => 'integer',
            'initial_price' => 'decimal:2',
            'order_published_at' => 'datetime',
            'order_updated_at' => 'datetime',
            'order_submission_start_at' => 'datetime',
            'order_submission_end_at' => 'datetime',
            'order_scoring_at' => 'datetime',
        ];
    }
}
