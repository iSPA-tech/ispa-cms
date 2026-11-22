<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

/**
 * Static scan of a migration file for operations that drop or rename a column or table in its `up`.
 *
 * Modules every site depends on (Content, Account) may be upgraded online; a package that drops or renames data in
 * place would break sites still running code that reads it, so PackageBuilder refuses such packages (rule of
 * the Content online upgrade, design section 9.2). The whole file is read except the body of `down()`, which
 * legitimately drops what `up()` created (so helper methods called from `up()` are covered). Found:
 *  - a method or static call whose name starts with `drop` or `rename` (dropColumn, dropColumns, dropIfExists,
 *    Schema::drop, dropTimestamps, dropSoftDeletes, dropConstrainedForeignId, renameColumn, Schema::rename, ...),
 *    except index-only calls (dropIndex, dropUnique, dropPrimary, dropForeign, dropFullText, dropSpatialIndex,
 *    renameIndex) and file calls (`File::`, `Storage::`);
 *  - raw SQL passed as a string literal to `statement()` or `unprepared()`: `DROP TABLE|COLUMN`, `RENAME TABLE|COLUMN`,
 *    or `ALTER TABLE ... DROP|RENAME` of anything but an index, key, foreign key, constraint or primary key.
 * A file that can not be read gives no finding. The scan is static: an operation built at run time (variable method
 * names, SQL interpolated from variables or read from a file) is not seen, nor is a column narrowed with `change()`;
 * review still applies.
 */
final class DestructiveMigrationScanner
{
    /** Index-only calls that never remove data. */
    private const INDEX_ONLY = ['dropindex', 'dropunique', 'dropprimary', 'dropforeign', 'dropfulltext', 'dropspatialindex', 'renameindex'];

    /** Raw SQL that drops or renames a table or column (index, key and constraint changes excluded). */
    private const RAW_SQL = '/\b(?:DROP\s+(?:TABLE|COLUMN)\b|RENAME\s+(?:TABLE|COLUMN)\b|ALTER\s+TABLE\b.*\b(?:DROP|RENAME)\s+(?!(?:INDEX|KEY|FOREIGN|CONSTRAINT|PRIMARY|UNIQUE)\b)\w)/is';

    /** Calls whose string arguments are raw SQL. */
    private const SQL_CALLS = ['statement', 'unprepared'];

    /** Static classes whose drop/rename calls touch files, not the database. */
    private const FILE_CLASSES = ['file', 'storage'];

    /**
     * Destructive operations found in the `up()` of a migration file, in source order, without duplicates.
     *
     * @param string $path migration file
     *
     * @return list<string> call names (`dropColumn`) or `raw SQL`
     */
    public static function scan(string $path): array
    {
        $source = is_file($path) ? @file_get_contents($path) : false;

        return is_string($source) ? self::scanSource($source) : [];
    }

    /**
     * Same as {@see self::scan()} for PHP source text.
     *
     * @param string $source PHP source of one migration file
     *
     * @return list<string>
     */
    public static function scanSource(string $source): array
    {
        $body = self::withoutDown(token_get_all($source));
        $found = [];
        $count = count($body);
        for ($i = 0; $i < $count; $i++) {
            $token = $body[$i];
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $previous = self::previousSignificant($body, $i);
            if ($previous === null || ! in_array($previous, ['->', '?->', '::'], true)) {
                continue;
            }
            $name = strtolower($token[1]);
            if (in_array($name, self::SQL_CALLS, true)) {
                if (preg_match(self::RAW_SQL, self::callStrings($body, $i)) === 1) {
                    $found[] = 'raw SQL';
                }
                continue;
            }
            if ($previous === '::' && in_array(strtolower((string) self::previousSignificant($body, self::indexOfPrevious($body, $i))), self::FILE_CLASSES, true)) {
                continue;
            }
            if ((str_starts_with($name, 'drop') || str_starts_with($name, 'rename')) && ! in_array($name, self::INDEX_ONLY, true)) {
                $found[] = $token[1];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Tables a migration creates (`Schema::create('name'`) and alters (`Schema::table('name'`) outside `down()`, read
     * statically from its source (technical sheet). Only a string literal first argument is seen; a table
     * name built at run time is not reported.
     *
     * @param string $source PHP source of one migration file
     *
     * @return array{created: list<string>, altered: list<string>} each without duplicates, in source order
     */
    public static function tableOperations(string $source): array
    {
        $body = self::withoutDown(token_get_all($source));
        $out = ['created' => [], 'altered' => []];
        $count = count($body);
        for ($i = 0; $i < $count; $i++) {
            $token = $body[$i];
            if (! is_array($token) || $token[0] !== T_STRING || self::previousSignificant($body, $i) !== '::') {
                continue;
            }
            $kind = match (strtolower($token[1])) {
                'create' => 'created',
                'table' => 'altered',
                default => null,
            };
            $class = strtolower((string) self::previousSignificant($body, self::indexOfPrevious($body, $i)));
            if ($kind === null || ($class !== 'schema' && ! str_ends_with($class, '\\schema'))) {
                continue;
            }
            $table = self::firstStringArgument($body, $i);
            if ($table !== null && $table !== '' && ! in_array($table, $out[$kind], true)) {
                $out[$kind][] = $table;
            }
        }

        return $out;
    }

    /**
     * Value of the first argument of the call whose name is at $i when it is a plain string literal, else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function firstStringArgument(array $tokens, int $i): ?string
    {
        $count = count($tokens);
        $j = $i + 1;
        $seenParen = false;
        for (; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (! $seenParen) {
                if ($token !== '(') {
                    return null;
                }
                $seenParen = true;
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return stripcslashes(substr($token[1], 1, -1));
            }

            return null;
        }

        return null;
    }

    /**
     * String literals inside the argument list of the call whose name is at $i (joined with spaces).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function callStrings(array $tokens, int $i): string
    {
        $count = count($tokens);
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            return '';
        }
        $depth = 0;
        $parts = [];
        for (; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                if (--$depth === 0) {
                    break;
                }
            } elseif (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $parts[] = $token[1];
            } elseif (is_array($token) && in_array($token[0], [T_START_HEREDOC], true)) {
                $parts[] = ' ';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Index of the nearest significant token before $i (or -1).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function indexOfPrevious(array $tokens, int $i): int
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if (! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * All tokens except the body of `function down(...)` (its braces included).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function withoutDown(array $tokens): array
    {
        $tokens = array_values($tokens);
        $count = count($tokens);
        $kept = [];
        for ($i = 0; $i < $count; $i++) {
            $kept[] = $tokens[$i];
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || ! is_array($tokens[$j]) || strtolower($tokens[$j][1]) !== 'down') {
                continue;
            }
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            $depth = 0;
            for (; $j < $count; $j++) {
                $token = $tokens[$j];
                $text = is_array($token) ? $token[1] : $token;
                if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    break;
                }
            }
            $i = $j;
        }

        return $kept;
    }

    /**
     * Text of the nearest token before $i that is not whitespace or a comment.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousSignificant(array $tokens, int $i): ?string
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }
}
