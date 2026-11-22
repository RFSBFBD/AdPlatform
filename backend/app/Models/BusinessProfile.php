<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $fillable = [
        'user_id', 'business_name', 'business_model', 'currency', 'primary_goal',
        'gross_margin_percent', 'average_order_value', 'budget_cap', 'budget_cap_horizon', 'target_roas',
        'conversion_definition', 'notes', 'custom_fields',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'gross_margin_percent' => 'float',
        'average_order_value' => 'float',
        'budget_cap' => 'float',
        'target_roas' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
