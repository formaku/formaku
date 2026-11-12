<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->needsHistoryIndex()) {
            Schema::table('formaku_revisions', function (Blueprint $table): void {
                $table->index(['record_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        if ($this->needsHistoryIndex()) {
            Schema::table('formaku_revisions', function (Blueprint $table): void {
                $table->dropIndex(['record_id', 'id']);
            });
        }
    }

    private function needsHistoryIndex(): bool
    {
        // InnoDB already indexes the foreign key and appends the primary key.
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
