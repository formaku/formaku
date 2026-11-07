<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_assistants', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->uuid('connection_id')->unique();
            $table->foreignId('collection_id')->constrained('formaku_collections')->cascadeOnDelete();
            $table->boolean('can_publish');
            $table->char('pairing_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::create('formaku_assistant_creates', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->uuid('request_id');
            $table->char('fingerprint', 64);
            $table->foreignId('record_id')->constrained('formaku_records')->cascadeOnDelete();
            $table->primary(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_assistant_creates');
        Schema::dropIfExists('formaku_assistants');
    }
};
