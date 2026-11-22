<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! $this->supportsFulltextIndex()) {
            return;
        }

        if (! $this->hasFulltextIndex()) {
            DB::statement('ALTER TABLE `translates` ADD FULLTEXT INDEX `translates_fulltext_search` (`name`, `text`)');
        }
    }

    public function down(): void
    {
        if (! $this->supportsFulltextIndex()) {
            return;
        }

        if ($this->hasFulltextIndex()) {
            Schema::table('translates', function ($table) {
                $table->dropIndex('translates_fulltext_search');
            });
        }
    }

    private function supportsFulltextIndex(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    private function hasFulltextIndex(): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `translates` WHERE Key_name = 'translates_fulltext_search'");

        return count($indexes) > 0;
    }
};
