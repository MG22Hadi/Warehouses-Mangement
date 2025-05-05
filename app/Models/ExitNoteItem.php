<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExitNoteItem extends Model
{
    protected $fillable = [
        'exit_note_id',
        'product_id',
        'quantity',
        'notes'
    ];

    public function exitNote()
    {
        return $this->belongsTo(ExitNote::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
} 