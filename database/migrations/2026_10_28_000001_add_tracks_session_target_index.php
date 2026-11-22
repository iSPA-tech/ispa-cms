<?php

use App\Constants\Tables;
use App\Helpers\SchemaHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrackingAggregateWriter::applyLegacy() looks up the existing track row by
 * `session_id = ? AND target_type = ? AND target_id = ?`. `tracks` only had
 * `(target_id, target_type)`, and site-level views all share target_id = 0 /
 * target_type NULL, so on ARC (1.29M rows, 486k of them site-level) each
 * receipt scanned ~0.5M rows and took 23-36s to apply. The tracking worker
 * then fell behind arrivals and the pending re-dispatcher piled up 17M
 * duplicate jobs in Redis (2026-10-28 incident on .140).
 *
 * session_id leads because it is the selective column.
 */
return new class extends Migration
{
    private const INDEX = 'tracks_session_id_target_id_target_type_index';

    public function up(): void
    {
        if (Schema::hasTable(Tables::TRACKS) && ! Schema::hasIndex(Tables::TRACKS, self::INDEX)) {
            Schema::table(Tables::TRACKS, static function (Blueprint $table): void {
                $table->index(['session_id', 'target_id', 'target_type'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(Tables::TRACKS)) {
            Schema::table(Tables::TRACKS, static function (Blueprint $table): void {
                SchemaHelper::dropIndexIfExists($table, Tables::TRACKS, self::INDEX);
            });
        }
    }
};
