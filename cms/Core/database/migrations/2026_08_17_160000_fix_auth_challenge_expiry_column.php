<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `expires_at` DATETIME NOT NULL',
            CoreTableConstant::AUTH_CHALLENGES,
        ));
    }

    public function down(): void
    {
        // Forward-only safety fix: restoring implicit ON UPDATE would break active MFA challenges.
    }
};
