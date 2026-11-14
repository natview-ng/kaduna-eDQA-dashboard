<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 step 4: the Rounds page shows who closed a round as well as when. rounds.closed_by is the
 * administrator who closed it (the audit log keeps every close and reopen; this is the current
 * one), cleared on reopen, and never set on an open round. Rounds closed before this column have
 * none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rounds ADD COLUMN closed_by bigint REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE rounds ADD CONSTRAINT closed_by_only_when_closed CHECK (closed_by IS NULL OR status = 'closed')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rounds DROP CONSTRAINT IF EXISTS closed_by_only_when_closed');
        DB::statement('ALTER TABLE rounds DROP COLUMN IF EXISTS closed_by');
    }
};
