<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('firebase_uid')->unique(); // El ID seguro que nos manda Firebase
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('nombre');
            $table->string('apellido');
            $table->string('phone')->nullable();
            $table->string('plan_activo')->default('ninguno'); // redstone, hierro, diamante, etc.
            $table->string('role')->default('usuario'); // usuario, soporte, ceo
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('clientes');
    }
};