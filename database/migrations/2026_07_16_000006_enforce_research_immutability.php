<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_immutable_research_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'research_manifests' OR OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'immutable research records cannot be modified';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS strategy_versions_immutable ON strategy_versions');
        DB::statement('DROP TRIGGER IF EXISTS universe_versions_immutable ON universe_versions');
        DB::statement('DROP TRIGGER IF EXISTS research_manifests_immutable ON research_manifests');
        DB::statement('CREATE TRIGGER strategy_versions_immutable BEFORE UPDATE OR DELETE ON strategy_versions FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
        DB::statement('CREATE TRIGGER universe_versions_immutable BEFORE UPDATE OR DELETE ON universe_versions FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
        DB::statement('CREATE TRIGGER research_manifests_immutable BEFORE UPDATE OR DELETE ON research_manifests FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS strategy_versions_immutable ON strategy_versions');
        DB::statement('DROP TRIGGER IF EXISTS universe_versions_immutable ON universe_versions');
        DB::statement('DROP TRIGGER IF EXISTS research_manifests_immutable ON research_manifests');
        DB::statement('DROP FUNCTION IF EXISTS reject_immutable_research_mutation()');
    }
};
