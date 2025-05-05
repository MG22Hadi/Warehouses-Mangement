<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntryNote extends Model
{
    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'serial_number',
        'date',
        'created_by'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(WarehouseKeeper::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(EntryNoteItem::class);
    }
} 