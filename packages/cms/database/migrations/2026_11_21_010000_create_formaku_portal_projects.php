<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_portal_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('provider');
            $table->string('state');
            $table->unsignedInteger('version')->default(1);
            $table->text('brief');
            $table->text('materials');
            $table->unsignedInteger('budget_cents')->default(0);
            $table->unsignedInteger('cost_cents')->nullable();
            $table->json('proposal')->nullable();
            $table->json('snapshot');
            $table->json('pages')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_portal_projects');
    }
};
