<?php

declare(strict_types=1);

namespace App\Models\Platforms\Eis;

use Database\Factories\Platforms\Eis\EisDetailsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Extension 1:1 к закупке — ЕИС-специфичные поля (ИКЗ, обеспечение, признаки, ЭТП).
 */
#[UseFactory(EisDetailsFactory::class)]
#[Fillable([
    'procurement_id',
    'ikz_year',
    'ikz_customer_code',
    'ikz_plan_number',
    'ikz_plangraph_number',
    'ikz_okpd_class',
    'ikz_okpd_subclass',
    'ikz_okpd_group',
    'ikz_kvr',
    'plan_graph_position',
    'plan_graph_url',
    'bid_security_required',
    'bid_security_percent',
    'bid_security_amount',
    'performance_security_required',
    'performance_security_percent',
    'performance_security_amount',
    'is_smp_only',
    'anti_dumping_applied',
    'treasury_support',
    'advantages',
    'national_regime',
    'participant_requirements',
    'etp_name',
    'etp_url',
    'execution_starts_at',
    'execution_ends_at',
    'contact_person',
    'print_form_urls',
])]
#[Table(name: 'prc_eis_details', key: 'procurement_id')]
#[WithoutIncrementing]
final class EisDetails extends Model
{
    /** @use HasFactory<EisDetailsFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ikz_year' => 'integer',
            'bid_security_required' => 'boolean',
            'bid_security_percent' => 'decimal:2',
            'bid_security_amount' => 'decimal:2',
            'performance_security_required' => 'boolean',
            'performance_security_percent' => 'decimal:2',
            'performance_security_amount' => 'decimal:2',
            'is_smp_only' => 'boolean',
            'anti_dumping_applied' => 'boolean',
            'treasury_support' => 'boolean',
            'advantages' => AsCollection::class,
            'national_regime' => AsCollection::class,
            'participant_requirements' => AsCollection::class,
            'contact_person' => AsCollection::class,
            'print_form_urls' => AsCollection::class,
        ];
    }
}
