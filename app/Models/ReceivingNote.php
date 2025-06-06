<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivingNote extends Model
{
    protected $fillable = [
        'supplier_id',
        'created_by',
        'serial_number',
        'date'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }


    public function createdBy()
    {
        return $this->belongsTo(WarehouseKeeper::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(ReceivingNoteItem::class);
    }
}
