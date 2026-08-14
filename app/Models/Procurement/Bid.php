<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Enums\Procurement\BidStatus;
use Database\Factories\Procurement\BidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Заявка/предложение участника, построчно из протокола или результатов.
 */
#[UseFactory(BidFactory::class)]
#[Fillable([
    'procurement_id',
    'lot_id',
    'protocol_id',
    'organization_id',
    'bid_number',
    'participant_ordinal',
    'price',
    'status',
    'reject_reason',
    'final_rank',
])]
#[Table(name: 'prc_bids')]
final class Bid extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'participant_ordinal' => 'integer',
            'price' => 'decimal:2',
            'status' => BidStatus::class,
            'final_rank' => 'integer',
        ];
    }
}
