<?php

uses(\Tests\TestCase::class);

use Carbon\Carbon;
use App\Constants\Tables;
use Mockery\MockInterface;
use App\Http\Controllers\V2\Admin\TrackController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Cms\Content\Interfaces\PostInterface;
use App\Repositories\Interfaces\TrackInterface;
use App\Repositories\Interfaces\CommentInterface;
use App\Repositories\Interfaces\ContactInterface;

beforeEach(function () {
    Schema::dropIfExists(Tables::TRACKS);
    Schema::create(Tables::TRACKS, static function (Blueprint $table) {
        $table->id();
        $table->string('target_type')->nullable();
        $table->unsignedBigInteger('target_id')->nullable();
        $table->string('session_id')->nullable();
        $table->text('tags')->nullable();
        $table->unsignedInteger('view')->default(0);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists(Tables::TRACKS);
});

test('track controller can summarize daily totals without mysql stored procedures', function () {
    DB::table(Tables::TRACKS)->insert([
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 7,
            'session_id' => 'session-a',
            'tags' => '["page_view"]',
            'view' => 5,
            'created_at' => '2026-03-12 08:00:00',
            'updated_at' => '2026-03-12 08:00:00',
        ],
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 7,
            'session_id' => 'session-a',
            'tags' => '["scan"]',
            'view' => 1,
            'created_at' => '2026-03-12 09:00:00',
            'updated_at' => '2026-03-12 09:00:00',
        ],
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 7,
            'session_id' => 'session-b',
            'tags' => '["page_view"]',
            'view' => 4,
            'created_at' => '2026-03-12 10:00:00',
            'updated_at' => '2026-03-12 10:00:00',
        ],
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 99,
            'session_id' => 'session-c',
            'tags' => '["scan"]',
            'view' => 9,
            'created_at' => '2026-03-12 10:30:00',
            'updated_at' => '2026-03-12 10:30:00',
        ],
    ]);

    $controller = new class(
        mock(TrackInterface::class),
        mock(PostInterface::class),
        mock(ContactInterface::class),
        mock(CommentInterface::class),
    ) extends TrackController {
        public function exposeSummary(Carbon $start, Carbon $end, ?string $targetType = null, int|string|null $targetId = null): array
        {
            return $this->summarizeTracksForRange($start, $end, $targetType, $targetId);
        }
    };

    $summary = $controller->exposeSummary(
        Carbon::parse('2026-03-12 00:00:00'),
        Carbon::parse('2026-03-12 23:59:59'),
        'Cms\\Content\\Models\\Post',
        7
    );

    expect($summary)->toBe([
        'total_views' => 10,
        'total_visitor' => 2,
        'total_scan' => 1,
    ]);
});
