<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manager extends Model
{
    protected $fillable = ['name'];

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function purchaseRequests()
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function installationReports()
    {
        return $this->hasMany(InstallationReport::class, 'approved_by');
    }

    public function scrapNotes()
    {
        return $this->hasMany(ScrapNote::class, 'approved_by');
    }
} 