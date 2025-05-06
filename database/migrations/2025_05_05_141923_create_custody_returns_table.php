<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('custody_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('custody_returns');
    }
};
