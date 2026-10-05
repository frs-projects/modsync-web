<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('modsync_packs', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('minecraft_version', 32);
            $table->string('loader', 16);
            $table->string('unlisted_policy', 16)->default('quarantine');
            $table->timestamps();
        });

        Schema::create('modsync_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('modsync_packs')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('project_id', 64)->nullable();
            $table->string('version_id', 64)->nullable();
            $table->string('name');
            $table->string('version_name')->nullable();
            $table->text('description')->nullable();
            $table->string('path', 512);
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha512', 128)->nullable();
            $table->char('sha1', 40)->nullable();
            $table->json('urls');
            $table->string('upload_path')->nullable();
            $table->string('policy', 16);
            $table->string('side', 16);
            $table->string('page_url', 512)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->string('hash_error')->nullable();
            $table->string('latest_version_id', 64)->nullable();
            $table->string('latest_version_name')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['pack_id', 'path']);
            $table->index(['source', 'project_id']);
        });

        Schema::create('modsync_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('modsync_packs')->cascadeOnDelete();
            $table->string('version', 64);
            $table->string('minecraft_version', 32);
            $table->string('loader', 16);
            $table->longText('manifest');
            $table->char('content_hash', 64);
            $table->json('uploads');
            $table->unsignedInteger('files_count');
            $table->json('warnings');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pack_id', 'version']);
        });

        Schema::table('modsync_packs', function (Blueprint $table) {
            $table->foreignId('live_release_id')->nullable()->after('unlisted_policy')->constrained('modsync_releases')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modsync_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('live_release_id');
        });

        Schema::dropIfExists('modsync_releases');
        Schema::dropIfExists('modsync_files');
        Schema::dropIfExists('modsync_packs');
    }
};
