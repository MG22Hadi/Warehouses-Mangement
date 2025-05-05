<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExitNote extends Model
{
    protected $fillable = [
        'material_request_id',
        'warehouse_id',
        'serial_number',
        'date',
        'created_by'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
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
        return $this->hasMany(ExitNoteItem::class);
    }

    public function custodyItems()
    {
        return $this->hasMany(CustodyItem::class);
    }
} 