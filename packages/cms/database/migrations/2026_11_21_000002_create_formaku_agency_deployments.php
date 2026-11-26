<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_agency_deployments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('previous_id')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('digest', 64);
            $table->json('target');
            $table->json('intent');
            $table->json('baseline');
            $table->json('page_ids');
            $table->json('retained_page_ids');
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
        Schema::create('formaku_agency_package_head', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('deployment_id')->nullable();
        });
        DB::table('formaku_agency_package_head')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_agency_package_head');
        Schema::dropIfExists('formaku_agency_deployments');
    }
};
