<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use RuntimeException;
use Illuminate\Console\Command;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Support\Facades\File;

/**
 * Convert single-row INSERT SQL (complete-insert, skip-extended-insert) into a PHP seeder array file.
 * @package Cms\Core\Console
 */
class ConvertSqlSeederToPhpCommand extends Command
{
    protected $signature = 'cms:convert-sql-to-php {--input=} {--output=}';

    protected $description = 'Convert complete-insert SQL dump (one row per INSERT) into PHP array seeder file.';

    /**
     * Parse a single-row INSERT SQL dump and convert it into a PHP array seeder file.
     *
     * Requires both --input (source SQL path) and --output (destination PHP path) options.
     * Each INSERT statement is parsed into an associative array and written as a PHP return file.
     *
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $input = (string)$this->option('input');
        $output = (string)$this->option('output');

        if ($input === '' || $output === '') {
            $this->error('Both --input and --output options are required.');
            return self::FAILURE;
        }

        if (!File::exists($input) || !File::isFile($input)) {
            $this->error('Input file not found: ' . $input);
            return self::FAILURE;
        }

        try {
            $rows = $this->parseSqlFile($input);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            CMSLogger::error(['message' => $exception->getMessage(), 'exception' => $exception]);
            return self::FAILURE;
        }

        $this->writePhpSeeder($output, $rows, $input);
        $this->info('Converted ' . count($rows) . ' row(s) to PHP seeder: ' . $output);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSqlFile(string $path): array
    {
        $lines = preg_split('/\r?\n/', (string)File::get($path)) ?: [];
        $rows = [];

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if (!str_starts_with(strtolower($trimmed), 'insert into')) {
                continue;
            }

            $rows[] = $this->parseInsertLine($trimmed, $lineNumber + 1);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the INSERT line cannot be parsed.
     */
    private function parseInsertLine(string $line, int $lineNumber): array
    {
        $columnsStart = stripos($line, '(');
        if ($columnsStart === false) {
            throw new RuntimeException("Unable to locate column list on line {$lineNumber}");
        }

        $columnsEnd = $this->findClosingParenthesis($line, $columnsStart);
        if ($columnsEnd === null) {
            throw new RuntimeException("Unbalanced column parentheses on line {$lineNumber}");
        }

        $columnsSegment = substr($line, $columnsStart + 1, $columnsEnd - $columnsStart - 1);
        $columns = array_map(static fn(string $col) => trim($col, " `\t\n\r"), explode(',', $columnsSegment));

        $valuesKeyword = stripos($line, 'values', $columnsEnd);
        if ($valuesKeyword === false) {
            throw new RuntimeException("VALUES keyword not found on line {$lineNumber}");
        }

        $valuesStart = strpos($line, '(', $valuesKeyword);
        if ($valuesStart === false) {
            throw new RuntimeException("Unable to locate values list on line {$lineNumber}");
        }

        $valuesEnd = $this->findClosingParenthesis($line, $valuesStart);
        if ($valuesEnd === null) {
            throw new RuntimeException("Unbalanced values parentheses on line {$lineNumber}");
        }

        $valuesSegment = substr($line, $valuesStart + 1, $valuesEnd - $valuesStart - 1);
        $values = $this->splitValues($valuesSegment, $lineNumber);

        if (count($columns) !== count($values)) {
            throw new RuntimeException("Column/value count mismatch on line {$lineNumber}");
        }

        $assoc = [];
        foreach ($columns as $index => $column) {
            $assoc[$column] = $this->normalizeValue($values[$index]);
        }

        return $assoc;
    }

    private function findClosingParenthesis(string $input, int $openPos): ?int
    {
        $depth = 0;
        $length = strlen($input);
        for ($i = $openPos; $i < $length; $i++) {
            $char = $input[$i];
            if ($char === '(') {
                $depth++;
            } else if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     *
     * @throws RuntimeException When an unterminated string literal is encountered.
     */
    private function splitValues(string $values, int $lineNumber): array
    {
        $parts = [];
        $buffer = '';
        $inString = false;
        $escape = false;

        $length = strlen($values);
        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];

            if ($char === "'" && !$escape) {
                $inString = !$inString;
            }

            if ($char === ',' && !$inString) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            if ($char === '\\' && !$escape) {
                $escape = true;
            } else {
                $escape = false;
            }

            $buffer .= $char;
        }

        if ($inString) {
            throw new RuntimeException("Unterminated string literal on line {$lineNumber}");
        }

        if ($buffer !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private function normalizeValue(string $value): mixed
    {
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (preg_match('/^-?\\d+$/', $value) === 1) {
            return (int)$value;
        }

        if (preg_match('/^-?\\d*\\.\\d+$/', $value) === 1) {
            return (float)$value;
        }

        $value = trim($value);
        $isQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");

        if ($isQuoted) {
            $value = substr($value, 1, -1);
            // Decode MySQL dump escapes in one pass (\n, \r, \t, \0, \Z, \b; any other
            // escaped character, e.g. \\ or \', stands for itself).
            $value = preg_replace_callback('/\\\\(.)/s', static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '0' => "\0",
                'Z' => "\x1A",
                'b' => "\x08",
                default => $m[1],
            }, $value);
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writePhpSeeder(string $output, array $rows, string $input): void
    {
        \Cms\Core\Support\Testing\TranslationFileGuard::assertWritable($output);
        File::ensureDirectoryExists(dirname($output));
        $export = var_export($rows, true);
        $generatedAt = now()->toDateTimeString();
        $content = <<<PHP
<?php
/**
 * Auto-generated by cms:convert-sql-to-php
 * Source: {$input}
 * Generated at: {$generatedAt}
 */

return {$export};

PHP;

        File::put($output, $content . "\n");
    }
}

