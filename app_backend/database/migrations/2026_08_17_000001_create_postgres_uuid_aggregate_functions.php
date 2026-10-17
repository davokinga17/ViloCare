<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PostgreSQL does not natively provide MAX() or MIN() aggregate functions for the UUID type.
     * Laravel's Eloquent ofMany() and latestOfMany() methods automatically use the model's primary key
     * (which is a UUID in this application) as a tiebreaker aggregate.
     * Defining max(uuid) and min(uuid) custom aggregates makes all ofMany / latestOfMany queries
     * fully compatible with PostgreSQL.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE OR REPLACE FUNCTION max(uuid, uuid)
                RETURNS uuid AS $$
                BEGIN
                    RETURN GREATEST($1, $2);
                END;
                $$ LANGUAGE plpgsql IMMUTABLE;
            ');

            DB::statement('
                CREATE OR REPLACE FUNCTION min(uuid, uuid)
                RETURNS uuid AS $$
                BEGIN
                    RETURN LEAST($1, $2);
                END;
                $$ LANGUAGE plpgsql IMMUTABLE;
            ');

            DB::statement('
                DROP AGGREGATE IF EXISTS max(uuid);
            ');

            DB::statement('
                CREATE AGGREGATE max(uuid) (
                    SFUNC = max,
                    STYPE = uuid,
                    COMBINEFUNC = max,
                    PARALLEL = SAFE,
                    SORTOP = OPERATOR (>)
                );
            ');

            DB::statement('
                DROP AGGREGATE IF EXISTS min(uuid);
            ');

            DB::statement('
                CREATE AGGREGATE min(uuid) (
                    SFUNC = min,
                    STYPE = uuid,
                    COMBINEFUNC = min,
                    PARALLEL = SAFE,
                    SORTOP = OPERATOR (<)
                );
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP AGGREGATE IF EXISTS max(uuid);');
            DB::statement('DROP AGGREGATE IF EXISTS min(uuid);');
            DB::statement('DROP FUNCTION IF EXISTS max(uuid, uuid);');
            DB::statement('DROP FUNCTION IF EXISTS min(uuid, uuid);');
        }
    }
};
