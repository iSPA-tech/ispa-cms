<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use InvalidArgumentException;

/**
 * Fluent admin resource action definition (a named server operation).
 */
final class Action
{
    private ?string $labelKey = null;

    /** @var array{field: string, on: string, off: string}|null */
    private ?array $labelFrom = null;

    private ?string $routeName = null;

    /** @var array<string, mixed> */
    private array $payload = [];

    private string $method = 'POST';

    private ?string $confirmKey = null;

    /** @var array<string, mixed>|null */
    private ?array $visibleWhen = null;

    private ?string $ability = null;

    /** @var array<int, Field> */
    private array $fields = [];

    private ?string $successMessage = null;

    private ?string $submitLabel = null;

    private bool $danger = false;

    private bool $bulk = false;

    private ?string $afterHook = null;

    private ?string $refreshTarget = null;

    /** Allowed refresh targets. */
    private const REFRESH_TARGETS = ['list', 'record', 'close'];

    private function __construct(private readonly string $name) {}

    /** Start an action with the given name. */
    public static function make(string $name): static
    {
        return new self($name);
    }

    /** Set the label translation key. */
    public function label(string $key): static
    {
        $this->labelKey = $key;

        return $this;
    }

    /**
     * Choose the label by a record value: `$onKey` when the record's field is truthy, `$offKey` otherwise.
     *
     * The plain label stays the fallback for clients that do not read it.
     *
     * @param  string  $field  record attribute that decides
     * @param  string  $onKey  label translation key used when the value is truthy
     * @param  string  $offKey  label translation key used otherwise
     *
     * @throws InvalidArgumentException when a key or the field is empty
     */
    public function labelFrom(string $field, string $onKey, string $offKey): static
    {
        if ($field === '' || $onKey === '' || $offKey === '') {
            throw new InvalidArgumentException('The action label source needs a field and two translation keys.');
        }
        $this->labelFrom = ['field' => $field, 'on' => $onKey, 'off' => $offKey];

        return $this;
    }

    /**
     * Set the endpoint route name, fixed payload and HTTP method.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the method is not POST, PUT or DELETE
     */
    public function endpoint(string $routeName, array $payload = [], string $method = 'POST'): static
    {
        if (! in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unsupported action method [{$method}].");
        }

        $this->routeName = $routeName;
        $this->payload = $payload;
        $this->method = $method;

        return $this;
    }

    /** Ask for confirmation using the given message translation key. */
    public function confirm(string $key): static
    {
        $this->confirmKey = $key;

        return $this;
    }

    /**
     * Show the action only when the condition holds.
     *
     * @param  array<string, mixed>  $condition
     *
     * @throws InvalidArgumentException when the condition is malformed
     */
    public function visibleWhen(array $condition): static
    {
        Condition::validate($condition);
        $this->visibleWhen = $condition;

        return $this;
    }

    /** Set the permission ability needed to run the action. */
    public function ability(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Set the input fields collected before the action runs.
     *
     * @param  array<int, Field>  $fields
     *
     * @throws InvalidArgumentException when an item is not a Field
     */
    public function fields(array $fields): static
    {
        foreach ($fields as $field) {
            if (! $field instanceof Field) {
                throw new InvalidArgumentException('Action fields must be Field instances.');
            }
        }
        $this->fields = array_values($fields);

        return $this;
    }

    /** Set the success message translation key. */
    public function successMessage(string $key): static
    {
        $this->successMessage = $key;

        return $this;
    }

    /**
     * Set the submit button text of the action's input form (translation key).
     *
     * @throws InvalidArgumentException when the key is empty
     */
    public function submitLabel(string $key): static
    {
        if ($key === '') {
            throw new InvalidArgumentException('The action submit label needs a translation key.');
        }
        $this->submitLabel = $key;

        return $this;
    }

    /** Mark the action as destructive so the client styles it as dangerous. */
    public function danger(bool $danger = true): static
    {
        $this->danger = $danger;

        return $this;
    }

    /** Mark the action as a bulk action whose payload is `{uuids: [...]}`. */
    public function bulk(bool $bulk = true): static
    {
        $this->bulk = $bulk;

        return $this;
    }

    /** Name the client hook to run after the action succeeds. */
    public function after(string $hookName): static
    {
        $this->afterHook = $hookName;

        return $this;
    }

    /**
     * Set what the client refreshes after the action succeeds.
     *
     * @param  string  $target  one of `list`, `record`, `close`
     *
     * @throws InvalidArgumentException when the target is not allowed
     */
    public function refresh(string $target): static
    {
        if (! in_array($target, self::REFRESH_TARGETS, true)) {
            throw new InvalidArgumentException("Unknown action refresh target [{$target}]; allowed: ".implode(', ', self::REFRESH_TARGETS).'.');
        }
        $this->refreshTarget = $target;

        return $this;
    }

    /** Action name. */
    public function name(): string
    {
        return $this->name;
    }

    /** Label translation key. */
    public function getLabel(): ?string
    {
        return $this->labelKey;
    }

    /**
     * Value-dependent label source, if any.
     *
     * @return array{field: string, on: string, off: string}|null
     */
    public function getLabelFrom(): ?array
    {
        return $this->labelFrom;
    }

    /** Endpoint route name. */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /**
     * Fixed payload sent with the action.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** HTTP method of the endpoint. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** Confirmation message translation key. */
    public function getConfirm(): ?string
    {
        return $this->confirmKey;
    }

    /**
     * Visibility condition, if any.
     *
     * @return array<string, mixed>|null
     */
    public function getVisibleWhen(): ?array
    {
        return $this->visibleWhen;
    }

    /** Permission ability needed to run the action. */
    public function getAbility(): ?string
    {
        return $this->ability;
    }

    /**
     * Input fields collected before running.
     *
     * @return array<int, Field>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** Success message translation key. */
    public function getSuccessMessage(): ?string
    {
        return $this->successMessage;
    }

    /** Submit button text translation key of the input form. */
    public function getSubmitLabel(): ?string
    {
        return $this->submitLabel;
    }

    /** Whether the action is destructive. */
    public function isDanger(): bool
    {
        return $this->danger;
    }

    /** Whether the action is a bulk action. */
    public function isBulk(): bool
    {
        return $this->bulk;
    }

    /** Client hook name run after success, if any. */
    public function getAfter(): ?string
    {
        return $this->afterHook;
    }

    /** Refresh target after success (`list`, `record`, `close`), if any. */
    public function getRefresh(): ?string
    {
        return $this->refreshTarget;
    }
}
