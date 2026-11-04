<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formaku_collections', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('kind');
            $t->unsignedInteger('version')->default(1);
            $t->json('definition');
            $t->timestamps();
        });
        Schema::create('formaku_schema_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('collection_id')->constrained('formaku_collections');
            $t->unsignedInteger('version');
            $t->json('definition');
            $t->timestamps();
            $t->unique(['collection_id', 'version']);
        });
        Schema::create('formaku_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('collection_id')->constrained('formaku_collections');
            $t->unsignedBigInteger('owner_id')->nullable()->index();
            $t->unsignedBigInteger('assignee_id')->nullable()->index();
            $t->string('state')->default('new')->index();
            $t->unsignedInteger('version')->default(0);
            $t->unsignedBigInteger('draft_id')->nullable();
            $t->unsignedBigInteger('published_id')->nullable();
            $t->string('published_slug')->nullable()->unique();
            $t->string('published_title')->nullable();
            $t->text('search_text')->nullable();
            $t->timestamp('publish_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('formaku_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('formaku_records');
            $t->unsignedInteger('schema_version');
            $t->json('data');
            $t->unsignedBigInteger('author_id')->nullable();
            $t->timestamps();
        });
        Schema::create('formaku_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('revision_id')->constrained('formaku_revisions');
            $t->string('field');
            $t->foreignId('target_id')->constrained('formaku_records');
            $t->unique(['revision_id', 'field', 'target_id']);
        });
        Schema::create('formaku_media', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_id')->index();
            $t->string('disk');
            $t->string('path');
            $t->string('name');
            $t->string('mime');
            $t->string('visibility');
            $t->string('alt')->default('');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('formaku_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('record_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('type');
            $t->json('payload');
            $t->timestamps();
        });
        Schema::create('formaku_ai_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('provider');
            $t->string('state')->default('queued');
            $t->text('prompt');
            $t->json('result')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('formaku_releases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('commit', 40);
            $t->string('state');
            $t->string('run_id')->nullable();
            $t->string('run_url')->nullable();
            $t->json('manifest');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['releases', 'ai_tasks', 'events', 'media', 'relations', 'revisions', 'records', 'schema_versions', 'collections'] as $n) {
            Schema::dropIfExists('formaku_'.$n);
        }
    }
};
