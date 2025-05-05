<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialRequest extends Model
{
    protected $fillable = [
        'requested_by',
        'manager_id',
        'warehouse_keeper_id',
        'serial_number',
        'date'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function manager()
    {
        return $this->belongsTo(Manager::class);
    }

    public function warehouseKeeper()
    {
        return $this->belongsTo(WarehouseKeeper::class);
    }

    public function items()
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function exitNotes()
    {
        return $this->hasMany(ExitNote::class);
    }
} 