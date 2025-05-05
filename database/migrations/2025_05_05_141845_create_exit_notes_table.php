<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('exit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained('material_requests');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('serial_number')->unique();
            $table->date('date');
            $table->foreignId('created_by')->constrained('warehouse_keepers');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('exit_notes');
    }
};
