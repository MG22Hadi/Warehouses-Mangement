<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodyReturn extends Model
{
    protected $fillable = [
        'custody_item_id',
        'quantity',
        'date',
        'notes'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function custodyItem()
    {
        return $this->belongsTo(CustodyItem::class);
    }
} 