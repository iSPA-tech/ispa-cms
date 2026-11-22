#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/sql_to_php_seed.php <input.sql> <output.php>\n");
    exit(1);
}

$input = $argv[1];
$output = $argv[2];

if (!is_file($input)) {
    fwrite(STDERR, "Input file not found: {$input}\n");
    exit(1);
}

$sql = file_get_contents($input);
if ($sql === false) {
    fwrite(STDERR, "Unable to read input file: {$input}\n");
    exit(1);
}

$statements = parseInsertStatements($sql);
$rows = [];
foreach ($statements as $statement) {
    $parsed = parseInsertStatement($statement);
    foreach ($parsed['rows'] as $row) {
        $rows[] = array_combine($parsed['columns'], $row);
    }
}

$content = "<?php\nreturn " . var_export($rows, true) . ";\n";

if (file_put_contents($output, $content) === false) {
    fwrite(STDERR, "Unable to write output file: {$output}\n");
    exit(1);
}

fwrite(STDOUT, "Exported " . count($rows) . " rows to {$output}\n");

function parseInsertStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inString = false;
    $escape = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;

        if ($char === "'" && !$escape) {
            $inString = !$inString;
        }

        $escape = ($char === '\\' && !$escape);

        if ($char === ';' && !$inString) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
        }

        if ($char !== '\\') {
            $escape = false;
        }
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return array_filter($statements, static fn(string $statement): bool => str_starts_with(strtoupper(ltrim($statement)), 'INSERT'));
}

function parseInsertStatement(string $statement): array
{
    $statement = trim($statement);

    $insertPos = stripos($statement, 'INSERT INTO');
    if ($insertPos !== 0) {
        throw new RuntimeException('Invalid INSERT statement: ' . $statement);
    }

    $afterInto = trim(substr($statement, strlen('INSERT INTO')));
    $columns = [];
    $tableName = null;

    if (preg_match('/^`?([a-zA-Z0-9_]+)`?\s*(\(.*)/s', $afterInto, $matches)) {
        $tableName = $matches[1];
        $columnsStart = strpos($statement, '(');
        $columnsEnd = findClosingParenthesis($statement, $columnsStart);
        if ($columnsEnd === null) {
            throw new RuntimeException('Unbalanced parentheses in column list: ' . $statement);
        }

        $columnsRaw = substr($statement, $columnsStart + 1, $columnsEnd - $columnsStart - 1);
        $columns = array_map(
            static fn(string $column): string => trim($column, " `\t\n\r"),
            explode(',', $columnsRaw)
        );

        $valuesSegment = trim(substr($statement, $columnsEnd + 1));
    } else {
        if (!preg_match('/^`?([a-zA-Z0-9_]+)`?\s+(.*)$/s', $afterInto, $tableMatches)) {
            throw new RuntimeException('Unable to parse table name: ' . $statement);
        }
        $tableName = $tableMatches[1];
        $valuesSegment = trim($tableMatches[2]);

        $firstRow = trim(substr($valuesSegment, stripos($valuesSegment, 'VALUES') + 6));
        $rows = parseValuesPart($firstRow);
        $columnsCount = count($rows[0] ?? []);
        $columns = array_map(static fn(int $index): string => 'column_' . $index, range(1, $columnsCount));
    }

    if (stripos($valuesSegment, 'VALUES') !== 0) {
        throw new RuntimeException('Missing VALUES keyword in statement: ' . $statement);
    }

    $valuesPart = trim(substr($valuesSegment, 6));

    return [
        'columns' => $columns,
        'rows' => parseValuesPart($valuesPart),
    ];
}

function findClosingParenthesis(string $input, int $openPos): ?int
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

function parseValuesPart(string $valuesPart): array
{
    $rows = [];
    $buffer = '';
    $depth = 0;
    $inString = false;
    $escape = false;

    $length = strlen($valuesPart);
    for ($i = 0; $i < $length; $i++) {
        $char = $valuesPart[$i];

        if ($char === "'" && !$escape) {
            $inString = !$inString;
        }

        if (!$inString) {
            if ($char === '(') {
                $depth++;
            } else if ($char === ')') {
                $depth--;
            }
        }

        $buffer .= $char;

        if ($depth === 0 && !$inString && $char === ')') {
            $rows[] = parseRow($buffer);
            $buffer = '';
            while ($i + 1 < $length && in_array($valuesPart[$i + 1], [",", " ", "\n", "\r", "\t"], true)) {
                $i++;
            }
        }

        $escape = ($char === '\\' && !$escape);
        if ($char !== '\\') {
            $escape = false;
        }
    }

    return $rows;
}

function parseRow(string $row): array
{
    $row = trim($row);
    $row = trim($row, '()');

    $values = [];
    $buffer = '';
    $inString = false;
    $escape = false;
    $length = strlen($row);

    for ($i = 0; $i < $length; $i++) {
        $char = $row[$i];

        if ($char === "'" && !$escape) {
            $inString = !$inString;
        }

        if ($char === ',' && !$inString) {
            $values[] = normalizeValue($buffer);
            $buffer = '';
            continue;
        }

        $buffer .= $char;

        $escape = ($char === '\\' && !$escape);
        if ($char !== '\\') {
            $escape = false;
        }
    }

    if ($buffer !== '') {
        $values[] = normalizeValue($buffer);
    }

    return $values;
}

function normalizeValue(string $value): mixed
{
    $value = trim($value);

    if ($value === 'NULL') {
        return null;
    }

    if ($value === 'true' || $value === 'false') {
        return $value === 'true';
    }

    if (is_numeric($value) && !str_starts_with($value, '0')) {
        return strpos($value, '.') !== false ? (float)$value : (int)$value;
    }

    if ($value === '') {
        return null;
    }

    if ($value[0] === "'" && substr($value, -1) === "'") {
        $value = substr($value, 1, -1);
        $value = str_replace("\\'", "'", $value);
        $value = str_replace('\\"', '"', $value);
        $value = str_replace('\\\\', '\\', $value);
        return $value;
    }

    return $value;
}
