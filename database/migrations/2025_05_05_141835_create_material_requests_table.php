<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('manager_id')->constrained('managers');
            $table->foreignId('warehouse_keeper_id')->constrained('warehouse_keepers');
            $table->string('serial_number')->unique();
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('material_requests');
    }
};
