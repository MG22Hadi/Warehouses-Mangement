<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'location',
        //'type'
    ];

    public function stock()
    {
        return $this->hasMany(Stock::class);
    }

    public function entryNotes()
    {
        return $this->hasMany(EntryNote::class);
    }

    public function receivingNotes()
    {
        return $this->hasMany(ReceivingNote::class);
    }

    public function exitNotes()
    {
        return $this->hasMany(ExitNote::class);
    }

    // علاقة: المستودع يمكن أن يحتوي على العديد من المواقع
    public function locations()
    {
        return $this->hasMany(Location::class);
    }
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
