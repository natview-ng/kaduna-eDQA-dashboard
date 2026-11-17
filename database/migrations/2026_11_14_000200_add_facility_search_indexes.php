<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Facility list searches name, code, ward and LGA with ILIKE '%…%', which a
 * btree cannot serve: trigram GIN indexes (pg_trgm) can. pg_trgm is a trusted extension (the
 * migrate role creates it as the database owner); docker/postgres/init-dev.sql and DEPLOY.md
 * §8.2 already provision it. down() drops the indexes only: the extension may serve others.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX facilities_name_trgm ON facilities USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX facilities_code_trgm ON facilities USING gin (code gin_trgm_ops)');
        DB::statement('CREATE INDEX wards_name_trgm ON wards USING gin (name gin_trgm_ops)');
        // CascadeStatusQuery reads the newest cascade change and the newest export on every
        // Facility list and Data sources page: two small partial indexes, not a log scan.
        DB::statement("CREATE INDEX activity_log_cascade_changes ON activity_log (id) WHERE properties->>'cascade' = 'true'");
        DB::statement("CREATE INDEX activity_log_cascade_exports ON activity_log (id) WHERE event = 'cascade_exported'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS activity_log_cascade_exports');
        DB::statement('DROP INDEX IF EXISTS activity_log_cascade_changes');
        DB::statement('DROP INDEX IF EXISTS wards_name_trgm');
        DB::statement('DROP INDEX IF EXISTS facilities_code_trgm');
        DB::statement('DROP INDEX IF EXISTS facilities_name_trgm');
    }
};
