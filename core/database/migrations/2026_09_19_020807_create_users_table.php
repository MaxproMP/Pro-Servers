<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('firebase_uid')->unique()->nullable();
            $table->string('username')->unique()->nullable();
            $table->string('email')->unique();
            $table->string('discord_id')->unique()->nullable();
            $table->string('name')->nullable();
            $table->string('nombre')->nullable();
            $table->string('apellido')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default('cliente');
            $table->string('plan_activo')->default('ninguno');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};