<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('receiving_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiving_note_id')->constrained('receiving_notes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 10, 2);
            $table->decimal('total_price', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('receiving_note_items');
    }
};
