<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()//
    {
        Schema::create('custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');

            //

            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
//             $table->foreignId('room_id')->nullable()->constrained('rooms');
    public function down()
    {
        Schema::dropIfExists('custodies');
    }
};
