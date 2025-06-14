<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodyReturn extends Model
{
//    protected $fillable = [
//        'custody_item_id',
//        'quantity',
//        'date',
//        'notes'
//    ];
//
//    protected $casts = [
//        'date' => 'date'
//    ];
//
//    public function custodyItem()
//    {
//        return $this->belongsTo(CustodyItem::class);
//    }

    protected $fillable = [
        'user_id',
        'return_date',
        'notes',
        'status',
        'processed_by_warehouse_keeper_id', // التعديل هنا
        'processed_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'processed_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class); // هدا هو المستخدم صاحب العهدة
    }

    //  العلاقة تشير إلى WarehouseKeeper
    public function processor()
    {
        return $this->belongsTo(WarehouseKeeper::class, 'processed_by_warehouse_keeper_id');
    }

    public function items()
    {
        return $this->hasMany(CustodyReturnItem::class);
    }
}
