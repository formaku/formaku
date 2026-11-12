<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_record_translations', function (Blueprint $table): void {
            $table->foreignId('record_id')->primary()->constrained('formaku_records')->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained('formaku_collections');
            $table->string('group_key', 100)->nullable();
            $table->string('locale', 12)->nullable();
            $table->unsignedBigInteger('version');
            $table->unique(['collection_id', 'group_key', 'locale'], 'formaku_translation_collection_group_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_record_translations');
    }
};
