<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queued exports (ARCHITECTURE.md §8, SECURITY.md §7). One row per export asked
 * for: its type, the dashboard filter and the options (format, whether assessor and source details
 * are included) as asked, who asked, and where it stands. The file lives on the private exports
 * disk; `path` is relative to it, and is cleared when the retention job deletes the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64);
            $table->jsonb('filter');
            $table->jsonb('options');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 16)->default('queued');
            $table->smallInteger('progress')->default(0);
            $table->integer('row_count')->nullable();
            $table->string('path')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->timestampTz('expires_at')->nullable();
            $table->index(['requested_by', 'created_at']);
        });

        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_status_valid CHECK (status IN ('queued', 'running', 'done', 'failed', 'expired'))");
        DB::statement('ALTER TABLE exports ADD CONSTRAINT exports_progress_valid CHECK (progress BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE exports ADD CONSTRAINT exports_row_count_valid CHECK (row_count IS NULL OR row_count >= 0)');
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_done_has_file CHECK (status <> 'done' OR path IS NOT NULL)");
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_expired_has_no_file CHECK (status <> 'expired' OR path IS NULL)");
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_options_object CHECK (jsonb_typeof(options) = 'object')");
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_filter_object CHECK (jsonb_typeof(filter) = 'object')");
        // The retention job's daily read: done exports past their expiry.
        DB::statement("CREATE INDEX exports_due_to_expire_idx ON exports (expires_at) WHERE status = 'done'");
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
