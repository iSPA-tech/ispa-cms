<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Validation rule vocabulary for operator-defined custom fields.
 *
 * Operators pick rules from a closed per-type set ({@see CustomFieldType::allowedRules()}); a definition is a map of
 * rule name => value, e.g. `['min' => 2, 'max' => 40, 'pattern' => '[A-Z]{2}\d+']`. This class checks a definition,
 * turns it into Laravel rules (server side) and into the schema subset the admin renderer understands.
 *
 * Limits:
 * - text: `min` 0..500, `max` 1..500 (values live in `meta_values_string.value`, 500 chars), `pattern`;
 *   `max:500` is always applied when no `max` is set.
 * - textarea: `min` 0..20000, `max` 1..20000; `max:20000` is always applied when no `max` is set.
 * - integer: `min`, `max` as PHP ints (signed 64-bit); strings and floats are rejected, so an overflowing JSON
 *   number (decoded as float) never passes.
 * - date: `date_min`, `date_max` as real `Y-m-d` dates; values must use `date_format:Y-m-d`.
 * - multiselect: `max` = maximum number of picked items, 1..50.
 * - boolean, select: nothing beyond `required`.
 * `min` must not exceed `max` (and `date_min` not exceed `date_max`).
 *
 * Select and multiselect need a non-empty option list; {@see toLaravel()} and {@see toSchema()} throw
 * InvalidArgumentException without one, as they do for any definition {@see validateDefinition()} rejects.
 *
 * Pattern safety: an operator pattern is a bare body without delimiters. It is only ever run as
 * `/^(?:BODY)$/Du` (anchored, `$` without trailing newline, UTF-8) and only through {@see matches()}, which lowers the
 * PCRE backtrack and recursion limits for the call. Laravel's `regex:` rule is never used.
 */
final class CustomFieldRules
{
    /** Longest accepted pattern body, in bytes. */
    public const PATTERN_MAX_LENGTH = 200;

    /** Largest number accepted inside a `{n}` / `{n,m}` quantifier. */
    public const REPEAT_MAX = 1000;

    /** pcre.backtrack_limit applied while matching an operator pattern. */
    private const BACKTRACK_LIMIT = 10000;

    /** pcre.recursion_limit applied while matching an operator pattern. */
    private const RECURSION_LIMIT = 1000;

    /** @var array<string, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}> [min range, max range] per type */
    private const LENGTH_LIMITS = [
        'text' => [[0, 500], [1, 500]],
        'textarea' => [[0, 20000], [1, 20000]],
    ];

    /** Multiselect item count range. */
    private const MULTISELECT_MAX = [1, 50];

    /**
     * Check a rule definition for a type.
     *
     * @param  array<mixed>  $rules  rule name => value
     * @return list<string> error messages; an empty list means the definition is valid
     */
    public function validateDefinition(CustomFieldType $type, array $rules): array
    {
        $errors = [];
        $allowed = $type->allowedRules();

        foreach (array_keys($rules) as $name) {
            if (! is_string($name) || ! in_array($name, $allowed, true)) {
                $errors[] = 'unknown rule: '.(is_string($name) ? $name : '#'.$name);
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        switch ($type) {
            case CustomFieldType::Text:
            case CustomFieldType::Textarea:
                [$minRange, $maxRange] = self::LENGTH_LIMITS[$type->value];
                $errors = [...$errors, ...$this->checkInt($rules, 'min', $minRange[0], $minRange[1])];
                $errors = [...$errors, ...$this->checkInt($rules, 'max', $maxRange[0], $maxRange[1])];
                break;
            case CustomFieldType::Integer:
                $errors = [...$errors, ...$this->checkInt($rules, 'min', PHP_INT_MIN, PHP_INT_MAX)];
                $errors = [...$errors, ...$this->checkInt($rules, 'max', PHP_INT_MIN, PHP_INT_MAX)];
                break;
            case CustomFieldType::Multiselect:
                $errors = [...$errors, ...$this->checkInt($rules, 'max', self::MULTISELECT_MAX[0], self::MULTISELECT_MAX[1])];
                break;
            case CustomFieldType::Date:
                foreach (['date_min', 'date_max'] as $name) {
                    if (array_key_exists($name, $rules) && ! $this->isDate($rules[$name])) {
                        $errors[] = $name.' must be a valid Y-m-d date';
                    }
                }
                if ($errors === [] && isset($rules['date_min'], $rules['date_max']) && $rules['date_min'] > $rules['date_max']) {
                    $errors[] = 'date_min must not exceed date_max';
                }
                break;
            default:
                break;
        }

        if ($errors === [] && isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
            $errors[] = 'min must not exceed max';
        }

        if (array_key_exists('pattern', $rules)) {
            foreach ($this->patternErrors($rules['pattern']) as $error) {
                $errors[] = 'pattern: '.$error;
            }
        }

        return $errors;
    }

    /**
     * Laravel rule list for one custom field value.
     *
     * Starts with `required` or `nullable`, then the base type rule, then the operator's limits. A pattern becomes a
     * rule object that calls {@see matches()}.
     *
     * @param  array<mixed>  $rules  rule name => value
     * @param  array<mixed>  $options  allowed values for select / multiselect
     * @return list<mixed>
     *
     * @throws InvalidArgumentException when the definition or options are invalid
     */
    public function toLaravel(CustomFieldType $type, array $rules, bool $required, array $options): array
    {
        $this->assertValid($type, $rules);
        $options = $this->optionList($type, $options);

        $laravel = [$required ? 'required' : 'nullable'];

        switch ($type) {
            case CustomFieldType::Text:
            case CustomFieldType::Textarea:
                $laravel[] = 'string';
                if (isset($rules['min'])) {
                    $laravel[] = 'min:'.$rules['min'];
                }
                $laravel[] = 'max:'.($rules['max'] ?? self::LENGTH_LIMITS[$type->value][1][1]);
                if (isset($rules['pattern'])) {
                    $laravel[] = $this->patternRule($rules['pattern']);
                }
                break;
            case CustomFieldType::Integer:
                $laravel[] = 'integer';
                if (isset($rules['min'])) {
                    $laravel[] = 'min:'.$rules['min'];
                }
                if (isset($rules['max'])) {
                    $laravel[] = 'max:'.$rules['max'];
                }
                break;
            case CustomFieldType::Boolean:
                $laravel[] = 'boolean';
                break;
            case CustomFieldType::Date:
                $laravel[] = 'date_format:Y-m-d';
                if (isset($rules['date_min'])) {
                    $laravel[] = 'after_or_equal:'.$rules['date_min'];
                }
                if (isset($rules['date_max'])) {
                    $laravel[] = 'before_or_equal:'.$rules['date_max'];
                }
                break;
            case CustomFieldType::Select:
                $laravel[] = 'string';
                $laravel[] = Rule::in($options);
                break;
            case CustomFieldType::Multiselect:
                $laravel[] = 'array';
                if (isset($rules['max'])) {
                    $laravel[] = 'max:'.$rules['max'];
                }
                // With the `array` rule present, Laravel's `in` checks every element and fails nested arrays.
                $laravel[] = Rule::in($options);
                $laravel[] = $this->distinctRule();
                break;
        }

        return $laravel;
    }

    /**
     * Schema subset for the admin renderer: only `required`, `min`, `max`, `pattern`, `options`.
     *
     * `min`/`max` are the effective limits (text/textarea always carry their storage `max`; date limits are `Y-m-d`
     * strings; multiselect `max` is an item count). `pattern` is the validated bare body; the renderer must wrap it
     * the same way as the server: `^(?:BODY)$`.
     *
     * @param  array<mixed>  $rules  rule name => value
     * @param  array<mixed>  $options  allowed values for select / multiselect
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the definition or options are invalid
     */
    public function toSchema(CustomFieldType $type, array $rules, bool $required, array $options): array
    {
        $this->assertValid($type, $rules);
        $options = $this->optionList($type, $options);

        $schema = ['required' => $required];
        $min = $type === CustomFieldType::Date ? ($rules['date_min'] ?? null) : ($rules['min'] ?? null);
        $max = $type === CustomFieldType::Date ? ($rules['date_max'] ?? null) : ($rules['max'] ?? null);
        if ($max === null && isset(self::LENGTH_LIMITS[$type->value])) {
            $max = self::LENGTH_LIMITS[$type->value][1][1];
        }

        if ($min !== null) {
            $schema['min'] = $min;
        }
        if ($max !== null) {
            $schema['max'] = $max;
        }
        if (isset($rules['pattern'])) {
            $schema['pattern'] = $rules['pattern'];
        }
        if ($options !== []) {
            $schema['options'] = $options;
        }

        return $schema;
    }

    /**
     * Guarded match of an operator pattern against a value.
     *
     * The pattern goes through every guard of {@see validateDefinition()} first; it is then run anchored as
     * `/^(?:BODY)$/Du` with lowered pcre.backtrack_limit / pcre.recursion_limit (restored afterwards).
     *
     * @return bool|null true = matches, false = does not match, null = rejected pattern or match error (treat the
     *                   input as invalid); never throws
     */
    public function matches(string $pattern, string $subject): ?bool
    {
        if ($this->patternErrors($pattern) !== []) {
            return null;
        }

        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');

        try {
            ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
            ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);
            $result = @preg_match($this->anchored($pattern), $subject);
            if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
                return null;
            }

            return $result === 1;
        } finally {
            if ($backtrack !== false) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }
            if ($recursion !== false) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }
    }

    /**
     * @param  array<mixed>  $rules
     *
     * @throws InvalidArgumentException
     */
    private function assertValid(CustomFieldType $type, array $rules): void
    {
        $errors = $this->validateDefinition($type, $rules);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid custom field rules: '.implode('; ', $errors));
        }
    }

    /**
     * Normalise select / multiselect options to a list of strings; other types ignore options.
     *
     * @param  array<mixed>  $options
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function optionList(CustomFieldType $type, array $options): array
    {
        if ($type !== CustomFieldType::Select && $type !== CustomFieldType::Multiselect) {
            return [];
        }
        if ($options === []) {
            throw new InvalidArgumentException('A '.$type->value.' field needs at least one option.');
        }

        $list = [];
        foreach ($options as $option) {
            if (! is_string($option) && ! is_int($option)) {
                throw new InvalidArgumentException('Options must be strings or integers.');
            }
            $list[] = (string) $option;
        }

        return array_values(array_unique($list));
    }

    /**
     * @param  array<mixed>  $rules
     * @return list<string>
     */
    private function checkInt(array $rules, string $name, int $min, int $max): array
    {
        if (! array_key_exists($name, $rules)) {
            return [];
        }
        $value = $rules[$name];
        if (! is_int($value) || $value < $min || $value > $max) {
            return [$name.' must be an integer between '.$min.' and '.$max];
        }

        return [];
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function anchored(string $pattern): string
    {
        return '/^(?:'.$pattern.')$/Du';
    }

    /**
     * All guards an operator pattern must pass before it may reach preg_match.
     *
     * 1. A string of 1..200 bytes, no NUL byte.
     * 2. Structural scan (escape and character-class aware), rejecting: an unescaped `/` anywhere (delimiter
     *    injection), a trailing lone backslash, `\Q`/`\E`, `\C`, backreferences and subroutines (`\1`..`\9`, `\0`,
     *    `\g`, `\k`), verbs `(*...)`, every `(?` group except `(?:`, `(?=`, `(?!`, `(?<=`, `(?<!` (so no recursion,
     *    callouts, conditionals, inline flags, comments or named groups), unbalanced parentheses, and any `{n}` /
     *    `{n,m}` number above 1000.
     * 3. Nested-quantifier heuristic: a group whose content holds an unbounded quantifier (`*`, `+`, `{n,}`) may not
     *    itself be repeated more than once (`*`, `+`, `{n,}`, `{n,m}` with m > 1, `{n}` with n > 1) — rejects
     *    `(a+)+`, `(.*)*`, `(a{1,}){2,}`, `(a+){2,10}`; and a group containing `|` may not carry an unbounded
     *    quantifier — rejects `(a|aa)+` (also benign `(cat|dog)+`; deliberately conservative). Polynomial cases such
     *    as `a*a*a*b` pass the heuristic and are stopped by the lowered backtrack limit in {@see matches()}.
     * 4. The anchored form `/^(?:BODY)$/Du` must compile.
     *
     * The scanner is conservative: e.g. `(?` inside a character class is rejected although PCRE would read it
     * literally; operators can escape such characters.
     *
     * @return list<string>
     */
    private function patternErrors(mixed $pattern): array
    {
        if (! is_string($pattern)) {
            return ['must be a string'];
        }
        $length = strlen($pattern);
        if ($length === 0 || $length > self::PATTERN_MAX_LENGTH) {
            return ['must be 1 to '.self::PATTERN_MAX_LENGTH.' characters'];
        }
        if (str_contains($pattern, "\0")) {
            return ['must not contain a NUL byte'];
        }
        if (! mb_check_encoding($pattern, 'UTF-8')) {
            return ['must be valid UTF-8'];
        }

        $error = $this->scan($pattern);
        if ($error !== null) {
            return [$error];
        }

        if (@preg_match($this->anchored($pattern), '') === false || preg_last_error() !== PREG_NO_ERROR) {
            return ['does not compile'];
        }

        return [];
    }

    /**
     * Structural scan of a pattern body; returns the first problem found or null.
     */
    private function scan(string $p): ?string
    {
        $n = strlen($p);
        // Stack of open groups; frame 0 is the whole body.
        $stack = [['unbounded' => false, 'alt' => false]];
        // Last repeatable atom: null, 'simple', or a closed group frame.
        $atom = null;
        $i = 0;

        while ($i < $n) {
            $c = $p[$i];

            if ($c === '/') {
                return 'must not contain an unescaped "/"';
            }

            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    return 'must not end with a backslash';
                }
                $e = $p[$i + 1];
                if (ctype_digit($e) || in_array($e, ['g', 'k', 'Q', 'E', 'C'], true)) {
                    return 'escape \\'.$e.' is not allowed';
                }
                $i += 2;
                if (in_array($e, ['x', 'p', 'P', 'N', 'o'], true) && $i < $n && $p[$i] === '{') {
                    $close = strpos($p, '}', $i);
                    if ($close === false) {
                        return 'unterminated escape';
                    }
                    $i = $close + 1;
                }
                $atom = 'simple';

                continue;
            }

            if ($c === '[') {
                $i = $this->skipClass($p, $i);
                if ($i < 0) {
                    return 'unterminated character class';
                }
                $atom = 'simple';

                continue;
            }

            if ($c === '(') {
                if ($i + 1 < $n && $p[$i + 1] === '*') {
                    return 'verbs (*...) are not allowed';
                }
                if ($i + 1 < $n && $p[$i + 1] === '?') {
                    $rest = substr($p, $i + 2, 2);
                    if ($rest !== '' && in_array($rest[0], [':', '=', '!'], true)) {
                        $i += 3;
                    } elseif ($rest === '<=' || $rest === '<!') {
                        $i += 4;
                    } else {
                        return 'only (?:, (?=, (?!, (?<= and (?<! groups are allowed';
                    }
                } else {
                    $i++;
                }
                $stack[] = ['unbounded' => false, 'alt' => false];
                $atom = null;

                continue;
            }

            if ($c === ')') {
                if (count($stack) < 2) {
                    return 'unbalanced parenthesis';
                }
                $frame = array_pop($stack);
                if ($frame['unbounded']) {
                    $stack[count($stack) - 1]['unbounded'] = true;
                }
                $atom = $frame;
                $i++;

                continue;
            }

            if ($c === '|') {
                $stack[count($stack) - 1]['alt'] = true;
                $atom = null;
                $i++;

                continue;
            }

            $quantifier = $this->readQuantifier($p, $i);
            if ($quantifier !== null) {
                [$min, $max, $next] = $quantifier;
                if ($min > self::REPEAT_MAX || ($max !== null && $max > self::REPEAT_MAX)) {
                    return 'repeat counts above '.self::REPEAT_MAX.' are not allowed';
                }
                $unbounded = $max === null;
                $repeats = $unbounded || $max > 1;
                if (is_array($atom)) {
                    if ($atom['unbounded'] && $repeats) {
                        return 'nested quantifiers are not allowed';
                    }
                    if ($atom['alt'] && $unbounded) {
                        return 'repeated alternation groups are not allowed';
                    }
                }
                if ($unbounded) {
                    $stack[count($stack) - 1]['unbounded'] = true;
                }
                $atom = null;
                $i = $next;
                // Lazy or possessive suffix.
                if ($i < $n && ($p[$i] === '?' || $p[$i] === '+')) {
                    $i++;
                }

                continue;
            }

            $atom = in_array($c, ['^', '$'], true) ? null : 'simple';
            $i++;
        }

        return count($stack) === 1 ? null : 'unbalanced parenthesis';
    }

    /**
     * Index just past a character class starting at $start, or -1 when unterminated.
     */
    private function skipClass(string $p, int $start): int
    {
        $n = strlen($p);
        $i = $start + 1;
        if ($i < $n && $p[$i] === '^') {
            $i++;
        }
        if ($i < $n && $p[$i] === ']') {
            $i++;
        }
        while ($i < $n) {
            $c = $p[$i];
            if ($c === '\\') {
                $i += 2;

                continue;
            }
            if ($c === '[' && $i + 1 < $n && $p[$i + 1] === ':') {
                $close = strpos($p, ':]', $i + 2);
                if ($close !== false) {
                    $i = $close + 2;

                    continue;
                }
            }
            if ($c === ']') {
                return $i + 1;
            }
            $i++;
        }

        return -1;
    }

    /**
     * Read a quantifier at $i.
     *
     * @return array{0: int, 1: int|null, 2: int}|null [min, max (null = unbounded), next index]; null when the
     *                                                  character is not a quantifier (`{` not followed by a valid
     *                                                  repeat is a literal in PCRE)
     */
    private function readQuantifier(string $p, int $i): ?array
    {
        $c = $p[$i];
        if ($c === '*') {
            return [0, null, $i + 1];
        }
        if ($c === '+') {
            return [1, null, $i + 1];
        }
        if ($c === '?') {
            return [0, 1, $i + 1];
        }
        if ($c === '{' && preg_match('/\G\{(\d+)(,(\d*))?\}/', $p, $m, 0, $i) === 1) {
            $min = $this->boundedInt($m[1]);
            $max = isset($m[2]) && $m[2] !== '' ? ($m[3] === '' ? null : $this->boundedInt($m[3])) : $min;

            return [$min, $max, $i + strlen($m[0])];
        }

        return null;
    }

    /**
     * Digits to int, capped so huge numbers cannot overflow (anything above REPEAT_MAX is rejected anyway).
     */
    private function boundedInt(string $digits): int
    {
        return strlen($digits) > 6 ? self::REPEAT_MAX + 1 : (int) $digits;
    }

    /**
     * Rule object running the guarded matcher; fails unless the value is a string that matches.
     */
    private function patternRule(string $pattern): ValidationRule
    {
        $rules = $this;

        return new class($rules, $pattern) implements ValidationRule
        {
            public function __construct(private readonly CustomFieldRules $rules, private readonly string $pattern) {}

            /**
             * Fail validation unless the guarded matcher returns true.
             */
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_string($value) || $this->rules->matches($this->pattern, $value) !== true) {
                    $fail('validation.regex')->translate();
                }
            }
        };
    }

    /**
     * Rule object rejecting repeated items in a multiselect value.
     */
    private function distinctRule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            /**
             * Fail validation when the array holds the same item twice.
             */
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_array($value)) {
                    return;
                }
                $seen = [];
                foreach ($value as $item) {
                    if (! is_scalar($item)) {
                        return;
                    }
                    $key = (string) $item;
                    if (isset($seen[$key])) {
                        $fail('validation.distinct')->translate();

                        return;
                    }
                    $seen[$key] = true;
                }
            }
        };
    }
}
