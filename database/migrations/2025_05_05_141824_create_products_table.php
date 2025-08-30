<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('unit');
            //$table->float('weight_per_unit', 10, 4)->nullable(); // وزن الوحدة الواحدة
            //$table->float('volume_per_unit', 10, 4)->nullable(); // حجم الوحدة الواحدة
            $table->boolean('consumable')->default(false);
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
