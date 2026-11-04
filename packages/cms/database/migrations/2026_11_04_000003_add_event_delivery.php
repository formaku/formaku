<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formaku_events', function (Blueprint $t) {
            $t->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('formaku_events', fn (Blueprint $t) => $t->dropColumn('notified_at'));
    }
};
