<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_portal_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('formaku_records')->cascadeOnDelete();
            $table->string('path')->unique();
            $table->string('name');
            $table->unsignedInteger('size');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_portal_attachments');
    }
};
