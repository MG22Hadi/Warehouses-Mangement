<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class WarehouseKeeper extends Model
{
    use HasApiTokens;
    protected $fillable = ['name', 'email', 'password', 'phone','warehouse_id',];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function entryNotes()
    {
        return $this->hasMany(EntryNote::class, 'created_by');
    }

    public function receivingNotes()
    {
        return $this->hasMany(ReceivingNote::class, 'created_by');
    }

    public function exitNotes()
    {
        return $this->hasMany(ExitNote::class, 'created_by');
    }

    public function purchaseRequests()
    {
        return $this->hasMany(PurchaseRequest::class, 'created_by');
    }

    public function installationReports()
    {
        return $this->hasMany(InstallationReport::class, 'created_by');
    }

    public function scrapNotes()
    {
        return $this->hasMany(ScrapNote::class, 'created_by');
    }

    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

}
