<?php

declare(strict_types=1);

namespace Cms\Core\SavedViews;

use Illuminate\Validation\ValidationException;

/**
 * Checks the filter state stored in a saved view.
 *
 * A state is a flat object of at most 20 keys named `[a-z0-9_]{1,40}` (never starting with `__`, never `constructor`
 * or `prototype`), whose values are strings of at most 200 characters, ints, or lists of at most 20 such strings or
 * ints. Nested objects, nested lists, floats, booleans and null are refused. The JSON encoding is at most 2048 bytes.
 */
final class SavedViewStateValidator
{
    /** Most keys in a state. */
    public const MAX_KEYS = 20;

    /** Most characters of a string value. */
    public const MAX_STRING = 200;

    /** Most items of a list value. */
    public const MAX_LIST = 20;

    /** Most bytes of the JSON encoded state. */
    public const MAX_BYTES = 2048;

    /** Key names refused even though they match the key pattern. */
    private const RESERVED_KEYS = ['constructor', 'prototype'];

    /**
     * Validate a state and return it unchanged.
     *
     * @param  mixed  $state  Decoded request value
     * @return array<string, mixed>
     *
     * @throws ValidationException on the `state` field when the state is not acceptable
     */
    public function validate(mixed $state): array
    {
        if (!is_array($state) || ($state !== [] && array_is_list($state))) {
            $this->fail('The state must be an object.');
        }
        if (count($state) > self::MAX_KEYS) {
            $this->fail('The state may not have more than ' . self::MAX_KEYS . ' keys.');
        }

        foreach ($state as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z0-9_]{1,40}$/', $key) !== 1 || str_starts_with($key, '__') || in_array($key, self::RESERVED_KEYS, true)) {
                $this->fail('The state has an invalid key name.');
            }

            if (is_array($value)) {
                if (!array_is_list($value) || count($value) > self::MAX_LIST) {
                    $this->fail('The state value of ' . $key . ' must be a list of at most ' . self::MAX_LIST . ' items.');
                }
                foreach ($value as $item) {
                    if (!$this->isScalar($item)) {
                        $this->fail('The state value of ' . $key . ' may only hold strings and integers.');
                    }
                }
            } elseif (!$this->isScalar($value)) {
                $this->fail('The state value of ' . $key . ' must be a string, an integer or a list of them.');
            }
        }

        $json = json_encode($state);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            $this->fail('The state may not be larger than ' . self::MAX_BYTES . ' bytes.');
        }

        return $state;
    }

    /**
     * Whether a value is an accepted scalar: an int or a string of at most 200 characters.
     */
    private function isScalar(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && mb_strlen($value) <= self::MAX_STRING);
    }

    /**
     * Throw a validation error on the `state` field.
     *
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['state' => [$message]]);
    }
}
