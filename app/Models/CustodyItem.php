<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodyItem extends Model
{
    protected $fillable = [
        'custody_id',
        'product_id',
        'exit_note_id',
        'quantity',
        'notes'
    ];

    public function custody()
    {
        return $this->belongsTo(Custody::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function exitNote()
    {
        return $this->belongsTo(ExitNote::class);
    }

    public function returns()
    {
        return $this->hasMany(CustodyReturn::class);
    }
}