<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('installation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('warehouse_keepers');
            $table->foreignId('approved_by')->nullable()->constrained('managers');
            $table->string('serial_number')->unique();
            $table->text('location');
            $table->enum('type', ['purchase', 'stock_usage']);
            $table->enum('status', ['pending','approved', 'rejected'])->default('pending');
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('installation_reports');
    }
};
