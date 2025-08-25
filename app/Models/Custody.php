<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Custody extends Model
{
    protected $fillable = [
        'user_id',
        'room_id',
        'date',
        'notes',
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function items()
    {
        return $this->hasMany(CustodyItem::class);
    }
}
