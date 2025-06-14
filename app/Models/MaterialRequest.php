<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialRequest extends Model
{
    protected $fillable = [
        'requested_by',
        'warehouse_keeper_id',
        'serial_number',
        'status',
        'date'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(Manager::class, 'approved_by');
    }

//    public function manager()
//    {
//        return $this->belongsTo(Manager::class, 'manager_id');
//    }

    public function warehouseKeeper()
    {
        return $this->belongsTo(WarehouseKeeper::class, 'warehouse_keeper_id');
    }

    public function items()
    {
        return $this->hasMany(MaterialRequestItem::class  );
    }

    public function exitNotes()
    {
        return $this->hasMany(ExitNote::class);
    }


}
