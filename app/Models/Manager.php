<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Manager extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'email', 'password', 'phone'];


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
