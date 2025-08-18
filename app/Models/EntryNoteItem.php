<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntryNoteItem extends Model
{
    protected $fillable = [
        'entry_note_id',
        'product_id',
        'warehouse_id',
        'location_id',
        'quantity',
        'notes'
    ];

    public function entryNote()
    {
        return $this->belongsTo(EntryNote::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
