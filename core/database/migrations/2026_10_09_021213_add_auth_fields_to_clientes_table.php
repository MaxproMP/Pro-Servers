<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('password')->after('email')->nullable(); // nullable para mantener compatibilidad si ya existían
            $table->text('google2fa_secret')->after('password')->nullable();
            
            // Hacer firebase_uid nullable porque ahora tendremos login propio
            $table->string('firebase_uid')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['password', 'google2fa_secret']);
            $table->string('firebase_uid')->nullable(false)->change();
        });
    }
};