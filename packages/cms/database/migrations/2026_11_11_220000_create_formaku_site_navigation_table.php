<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('formaku_site_navigation')) {
            Schema::create('formaku_site_navigation', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('version')->default(0);
                $table->json('settings')->nullable();
            });
        }

        DB::table('formaku_site_navigation')->insertOrIgnore(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_site_navigation');
    }
};
