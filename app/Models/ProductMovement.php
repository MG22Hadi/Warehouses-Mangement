<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMovement extends Model
{
    protected $fillable = [
        'product_id',
        'movement_type',
        'reference_serial_number',
        'quantity',
        'date',
        'image_path',
        'notes'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
} 