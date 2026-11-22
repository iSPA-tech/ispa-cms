<?php

declare(strict_types=1);

/**
 * Migration ownership for the Content tables.
 *
 * Rule: every migration that creates or alters
 * `contents`, `posts` and `bookmarks` lives in cms/Content/database/migrations, so the tables exist only where
 * Content is enabled and Content upgrades as one unit. No root database/migrations file touches them.
 * froze the old create/alter files in the root; they moved into cms/Content with unchanged file names
 * (the migrations table stores the base name only, so existing databases see nothing pending).
 * The generic rule for every module lives in RootMigrationOwnershipTest.
 */

/**
 * Pure lint over a map of migration basename => source. No application boot needed.
 */
final class ContentTableMigrationOwnershipLint
{
    /** Tables whose schema changes are owned by cms/Content. */
    public const OWNED_TABLES = ['contents', 'posts', 'bookmarks'];

    /** Table constant names that resolve to an owned table. */
    private const CONSTANT_TABLES = [
        'CONTENTS' => 'contents',
        'POSTS' => 'posts',
        'BOOKMARK' => 'bookmarks',
        'BOOKMARKS' => 'bookmarks',
    ];

    /**
     * Content table migrations that used to live in the root (FROZEN list) and now live in cms/Content.
     *
     * @var list<string>
     */
    public const MOVED_FROM_ROOT = [
        '2022_02_09_105541_create_contents_table.php',
        '2022_02_09_105657_create_posts_table.php',
        '2023_07_03_202525_update_content_table_custom_column.php',
        '2023_11_18_170009_create_bookmark_table.php',
        '2023_12_04_120758_update_post_descrition_data_length.php',
    ];

    /** Absolute path of the root migrations directory. */
    public static function rootDirectory(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** Absolute path of the Content module. */
    public static function moduleDirectory(): string
    {
        return dirname(__DIR__, 2).'/cms/Content';
    }

    /**
     * Read every PHP file of a directory as basename => source.
     *
     * @return array<string, string>
     */
    public static function readDirectory(string $dir): array
    {
        $files = [];
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    /**
     * Return one message per migration that touches an owned table through a Schema call.
     *
     * @param  array<string, string>  $files  basename => source
     * @return list<string>
     */
    public static function violations(array $files): array
    {
        $violations = [];
        foreach ($files as $name => $source) {
            $table = self::alteredTable($source);
            if ($table !== null) {
                $violations[] = sprintf('%s alters %s', $name, $table);
            }
        }

        return $violations;
    }

    /**
     * Owned table touched by a Schema call in the source, or null.
     * Matches Schema::create/table/drop/dropIfExists/rename/hasTable/hasIndex/hasColumn(s) with a literal table name
     * or a table constant.
     */
    public static function alteredTable(string $source): ?string
    {
        $pattern = '#\bSchema::(?:connection\([^)]*\)->)?(?:create|table|dropIfExists|drop|rename|hasTable|hasIndex|hasColumns?)\s*\(\s*'
            .'(?:[\'"](contents|posts|bookmarks)[\'"]'
            .'|\\\\?(?:[A-Za-z_]+\\\\)*(?:Tables|ContentTableConstant)::(CONTENTS|POSTS|BOOKMARKS?)\b)#';

        if (preg_match($pattern, $source, $matches) !== 1) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : self::CONSTANT_TABLES[$matches[2]];
    }
}

test('no root migration touches a Content table', function () {
    $files = ContentTableMigrationOwnershipLint::readDirectory(ContentTableMigrationOwnershipLint::rootDirectory());

    expect(ContentTableMigrationOwnershipLint::violations($files))->toBe([]);
});

test('the Content table migrations live in cms/Content with their original file names', function () {
    $root = array_keys(ContentTableMigrationOwnershipLint::readDirectory(ContentTableMigrationOwnershipLint::rootDirectory()));
    $module = array_keys(ContentTableMigrationOwnershipLint::readDirectory(
        ContentTableMigrationOwnershipLint::moduleDirectory().'/database/migrations'
    ));

    expect(array_values(array_intersect(ContentTableMigrationOwnershipLint::MOVED_FROM_ROOT, $root)))->toBe([])
        ->and(array_values(array_diff(ContentTableMigrationOwnershipLint::MOVED_FROM_ROOT, $module)))->toBe([]);
});

test('the empty order-index migration is gone from the root', function () {
    expect(is_file(ContentTableMigrationOwnershipLint::rootDirectory().'/2025_05_31_000000_add_indexes_to_order_tables.php'))
        ->toBeFalse();
});

test('a migration that touches an owned table fails the lint', function (string $source, string $table) {
    $files = ['2026_11_22_000001_add_flag_to_content.php' => $source];

    expect(ContentTableMigrationOwnershipLint::violations($files))
        ->toBe(["2026_11_22_000001_add_flag_to_content.php alters {$table}"]);
})->with([
    'literal table name' => ["<?php\nSchema::table('posts', function (\$t) { \$t->string('x'); });", 'posts'],
    'Tables constant' => ["<?php\nSchema::table(\\App\\Constants\\Tables::POSTS, function (\$t) { \$t->string('x'); });", 'posts'],
    'ContentTableConstant' => ["<?php\nSchema::table(Cms\\Content\\Constants\\ContentTableConstant::BOOKMARK, fn (\$t) => \$t->dropColumn('x'));", 'bookmarks'],
    'create contents' => ["<?php\nSchema::create(\"contents\", function (\$t) {});", 'contents'],
    'drop with connection' => ["<?php\nSchema::connection('mysql')->dropIfExists('posts');", 'posts'],
    'guarded index' => ["<?php\nif (Schema::hasTable('posts') && ! Schema::hasIndex('posts', 'x')) {}", 'posts'],
]);

test('migrations of other tables pass the lint', function () {
    $files = [
        '2026_11_22_000003_touch_other_table.php' => "<?php\nSchema::table('comments', function (\$t) {});",
    ];

    expect(ContentTableMigrationOwnershipLint::violations($files))->toBe([]);
});

test('every Content migration is marked irreversible for online upgrades', function () {
    $moduleDir = ContentTableMigrationOwnershipLint::moduleDirectory();
    $manifest = json_decode((string) file_get_contents($moduleDir.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $declared = $manifest['migrations']['irreversible'] ?? [];
    $all = array_map('basename', glob($moduleDir.'/database/migrations/*.php') ?: []);

    expect(array_values(array_diff($all, $declared)))->toBe([]);
    expect(array_values(array_diff($declared, $all)))->toBe([]);
});
