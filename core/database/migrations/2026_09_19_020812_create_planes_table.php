<?php

declare(strict_types=1);

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
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->decimal('precio_mensual', 8, 2);
            $table->integer('memoria_mb');
            $table->integer('cpu_cores');
            $table->integer('almacenamiento_gb');
            $table->check('precio_mensual >= 0');
            $table->check('memoria_mb > 0');
            $table->check('cpu_cores > 0');
            $table->check('almacenamiento_gb > 0');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
