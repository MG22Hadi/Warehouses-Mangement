<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallationMaterial extends Model
{
    protected $fillable = [
        'installation_report_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'total_price',
        'notes'
    ];

    public function installationReport()
    {
        return $this->belongsTo(InstallationReport::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
} 