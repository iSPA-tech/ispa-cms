<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
DO $$
DECLARE
    char_column record;
    char_length integer;
BEGIN
    FOR char_column IN
        SELECT
            c.table_schema,
            c.table_name,
            c.column_name,
            c.character_maximum_length
        FROM information_schema.columns AS c
        INNER JOIN information_schema.tables AS t
            ON t.table_schema = c.table_schema
            AND t.table_name = c.table_name
        WHERE c.table_schema = current_schema()
            AND t.table_type = 'BASE TABLE'
            AND c.data_type = 'character'
            AND c.character_maximum_length IS NOT NULL
    LOOP
        char_length := char_column.character_maximum_length;

        EXECUTE format(
            'UPDATE %I.%I SET %I = btrim(%I) WHERE %I IS NOT NULL',
            char_column.table_schema,
            char_column.table_name,
            char_column.column_name,
            char_column.column_name,
            char_column.column_name
        );

        EXECUTE format(
            'ALTER TABLE %I.%I ALTER COLUMN %I TYPE varchar(%s) USING btrim(%I)::varchar(%s)',
            char_column.table_schema,
            char_column.table_name,
            char_column.column_name,
            char_length,
            char_column.column_name,
            char_length
        );
    END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        // Do not reintroduce fixed-width character columns.
    }
};
