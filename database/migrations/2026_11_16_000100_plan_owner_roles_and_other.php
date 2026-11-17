<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The plan's owner is still a role or unit, never a person (D-79), but an
 * administrator may now name one the list lacks: owner_role 'other' with the role written out in
 * owner_role_other (a CHECK ties the two). Two more roles join the list: State HMIS team and LGA PHC
 * coordinator. down() puts every row back on a role the old list holds (the dimension's default).
 */
return new class extends Migration
{
    private const OLD_ROLES = ['lga_me_officer', 'state_me_team', 'programme_manager'];

    private const ROLES = ['lga_me_officer', 'state_me_team', 'programme_manager', 'state_hmis_team', 'lga_phc_coordinator', 'other'];

    private const DEFAULTS = ['availability' => 'lga_me_officer', 'consistency' => 'state_me_team', 'validity' => 'programme_manager'];

    public function up(): void
    {
        DB::statement('ALTER TABLE plan_actions ADD COLUMN owner_role_other varchar(80)');
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT plan_actions_owner_role_valid');
        DB::statement("ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_owner_role_valid CHECK (owner_role IN ('".implode("', '", self::ROLES)."'))");
        DB::statement("ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_owner_role_other CHECK ((owner_role = 'other') = (owner_role_other IS NOT NULL AND btrim(owner_role_other) <> ''))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT IF EXISTS plan_actions_owner_role_other');
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT IF EXISTS plan_actions_owner_role_valid');
        foreach (self::DEFAULTS as $dimension => $role) {
            DB::table('plan_actions')->where('dimension', $dimension)->whereNotIn('owner_role', self::OLD_ROLES)->update(['owner_role' => $role]);
        }
        DB::statement("ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_owner_role_valid CHECK (owner_role IN ('".implode("', '", self::OLD_ROLES)."'))");
        DB::statement('ALTER TABLE plan_actions DROP COLUMN owner_role_other');
    }
};
