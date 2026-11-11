<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_page_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('formaku_collections')->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('blocks');
            $table->timestamps();
            $table->index(['collection_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_page_templates');
    }
};
