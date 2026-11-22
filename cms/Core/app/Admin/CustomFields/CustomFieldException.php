<?php

declare(strict_types=1);

namespace Cms\Core\Admin\CustomFields;

use RuntimeException;

/**
 * Domain exception raised when a custom field definition cannot be saved.
 *
 * Every instance carries a machine `reason` code (stable, for the API layer) and an operator-safe English message
 * that never exposes table, column or class names.
 */
final class CustomFieldException extends RuntimeException
{
    public const RESOURCE_NOT_SUPPORTED = 'resource_not_supported';
    public const DUPLICATE_KEY = 'duplicate_key';
    public const TOO_MANY_FIELDS = 'too_many_fields';
    public const KEY_IMMUTABLE = 'key_immutable';
    public const TYPE_LOCKED = 'type_locked';
    public const OPTION_IN_USE = 'option_in_use';
    public const INVALID_DEFINITION = 'invalid_definition';
    public const UNKNOWN_FIELD = 'unknown_field';
    public const CONFIRM_MISMATCH = 'confirm_mismatch';
    public const NOT_CUSTOM_FIELD = 'not_custom_field';
    public const FIELD_PURGED = 'field_purged';
    public const BACKUP_REQUIRED = 'backup_required';

    /**
     * @param  string  $reason  machine reason code
     * @param  string  $message  operator-safe message
     * @param  list<string>  $errors  individual problems (invalid definitions only)
     */
    private function __construct(private readonly string $reason, string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** The resource does not exist or does not accept custom fields. */
    public static function resourceNotSupported(string $resourceKey): self
    {
        return new self(self::RESOURCE_NOT_SUPPORTED, "Custom fields are not available for [{$resourceKey}].");
    }

    /** Another field of the same resource already uses the key. */
    public static function duplicateKey(string $key): self
    {
        return new self(self::DUPLICATE_KEY, "A custom field with the key [{$key}] already exists.");
    }

    /** The resource reached its custom field limit. */
    public static function tooManyFields(int $limit): self
    {
        return new self(self::TOO_MANY_FIELDS, "A resource can have at most {$limit} custom fields.");
    }

    /** The key of an existing field cannot be changed. */
    public static function keyImmutable(string $key): self
    {
        return new self(self::KEY_IMMUTABLE, "The key of the custom field [{$key}] cannot be changed.");
    }

    /** The type cannot change because records already hold values. */
    public static function typeLocked(string $key): self
    {
        return new self(self::TYPE_LOCKED, "The type of the custom field [{$key}] cannot change because records already have values.");
    }

    /** An option cannot be removed because records still use it. */
    public static function optionInUse(string $key, string $value): self
    {
        return new self(self::OPTION_IN_USE, "The option [{$value}] of the custom field [{$key}] is still used by records and cannot be removed.");
    }

    /**
     * The submitted definition is invalid.
     *
     * @param  list<string>  $errors
     */
    public static function invalidDefinition(array $errors): self
    {
        return new self(self::INVALID_DEFINITION, 'The custom field definition is invalid: '.implode('; ', $errors).'.', $errors);
    }

    /** A submitted field uuid does not belong to the resource. */
    public static function unknownField(string $uuid): self
    {
        return new self(self::UNKNOWN_FIELD, "The custom field [{$uuid}] does not belong to this resource.");
    }

    /** The retyped key does not match the field key, so nothing is purged. */
    public static function confirmMismatch(string $key): self
    {
        return new self(self::CONFIRM_MISMATCH, "The text you typed does not match the field key [{$key}]. Nothing was deleted.");
    }

    /** The attribute is not a custom field and cannot go through the lifecycle actions. */
    public static function notCustomField(): self
    {
        return new self(self::NOT_CUSTOM_FIELD, 'Only custom fields can be archived, restored or purged.');
    }

    /** The field was purged; it stays removed and its key stays reserved. */
    public static function fieldPurged(string $key): self
    {
        return new self(self::FIELD_PURGED, "The custom field [{$key}] was purged and cannot be changed or restored.");
    }

    /** The backup that must exist before a purge could not be taken or is not usable, so nothing was deleted. */
    public static function backupRequired(): self
    {
        return new self(self::BACKUP_REQUIRED, 'A backup could not be made before deleting, so nothing was deleted.');
    }

    /** Machine reason code. */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Individual problems of an invalid definition; empty for other reasons.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
