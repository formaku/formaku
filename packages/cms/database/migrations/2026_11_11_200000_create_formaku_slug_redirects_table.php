<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_slug_redirects', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->foreignId('record_id')->constrained('formaku_records')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_slug_redirects');
    }
};
