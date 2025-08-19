<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name','manager_id', 'description'];


    public function manager()
    {
        return $this->belongsTo(Manager::class,'manager_id');
    }
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
