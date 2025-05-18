<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'code',
        'unit',
        'consumable',
        'notes'
    ];

    protected $casts = [
        'consumable' => 'boolean'
    ];

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function materialRequestItems()
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function entryNoteItems()
    {
        return $this->hasMany(EntryNoteItem::class);
    }

    public function receivingNoteItems()
    {
        return $this->hasMany(ReceivingNoteItem::class);
    }

    public function exitNoteItems()
    {
        return $this->hasMany(ExitNoteItem::class);
    }

    public function purchaseRequestItems()
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function custodyItems()
    {
        return $this->hasMany(CustodyItem::class);
    }

    public function installationMaterials()
    {
        return $this->hasMany(InstallationMaterial::class);
    }

    public function scrappedMaterials()
    {
        return $this->hasMany(ScrappedMaterial::class);
    }

    public function productMovements()
    {
        return $this->hasMany(ProductMovement::class);
    }
}
