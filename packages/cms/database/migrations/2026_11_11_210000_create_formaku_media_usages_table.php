<?php

use Formaku\Cms\Services\MediaUsage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('formaku_media_usages')) {
            Schema::create('formaku_media_usages', function (Blueprint $table) {
                $table->foreignId('revision_id')->constrained('formaku_revisions')->cascadeOnDelete();
                $table->unsignedBigInteger('media_id');
                $table->primary(['revision_id', 'media_id']);
                $table->index(['media_id', 'revision_id']);
            });
        }

        $usage = app(MediaUsage::class);
        DB::table('formaku_revisions as revision')
            ->join('formaku_records as current_record', 'current_record.id', '=', 'revision.record_id')
            ->join('formaku_collections as collection', 'collection.id', '=', 'current_record.collection_id')
            ->leftJoin('formaku_schema_versions as schema_version', fn ($join) => $join
                ->on('schema_version.collection_id', '=', 'collection.id')
                ->on('schema_version.version', '=', 'revision.schema_version'))
            ->where(fn ($query) => $query->whereColumn('revision.id', 'current_record.draft_id')
                ->orWhereColumn('revision.id', 'current_record.published_id'))
            ->select('revision.id as revision_id', 'revision.data', 'schema_version.definition as revision_definition', 'collection.definition as current_definition')
            ->chunkById(100, function (Collection $revisions) use ($usage) {
                foreach ($revisions as $revision) {
                    $definition = json_decode($revision->revision_definition ?? $revision->current_definition, true, flags: JSON_THROW_ON_ERROR);
                    $data = json_decode($revision->data, true, flags: JSON_THROW_ON_ERROR);
                    $usage->indexRevision($revision->revision_id, $definition, $data);
                }
            }, 'revision.id', 'revision_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('formaku_media_usages');
    }
};
