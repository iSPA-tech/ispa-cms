<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

/**
 * Fluent form view definition of an admin resource.
 */
final class FormView
{
    /** @var array<string, array<int, string>> */
    private array $contexts = [];

    private ?string $routeName = null;

    private string $method = 'POST';

    private ?string $updateRouteName = null;

    private string $updateMethod = 'PUT';

    private ?string $ability = null;

    private ?string $successMessage = null;

    /** @var array<string, string> Success message keys that replace the default for one context (create, update). */
    private array $contextSuccessMessages = [];

    private ?string $afterHook = null;

    /** Allowed label slots. */
    private const LABEL_SLOTS = ['title', 'hint', 'submit', 'required'];

    /** @var array<string, string> */
    private array $labels = [];

    /** Start an empty form view. */
    public static function make(): static
    {
        return new self;
    }

    /**
     * Start a form view with the create fields.
     *
     * @param  array<int, string>  $fieldNames
     */
    public static function create(array $fieldNames): static
    {
        return (new self)->withCreate($fieldNames);
    }

    /**
     * Declare the create fields.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function withCreate(array $fieldNames): static
    {
        $this->contexts['create'] = array_values($fieldNames);

        return $this;
    }

    /**
     * Declare the update fields.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function update(array $fieldNames): static
    {
        $this->contexts['update'] = array_values($fieldNames);

        return $this;
    }

    /** Set the create endpoint route name and HTTP method. */
    public function endpoint(string $routeName, string $method = 'POST'): static
    {
        $this->routeName = $routeName;
        $this->method = $method;

        return $this;
    }

    /** Set the update endpoint route name and HTTP method. */
    public function updateEndpoint(string $routeName, string $method = 'PUT'): static
    {
        $this->updateRouteName = $routeName;
        $this->updateMethod = $method;

        return $this;
    }

    /** Set the permission ability needed to use the form. */
    public function ability(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Set the success message translation key, for every context or, with `$context`, only for that context
     * (for example a create message that also says an invitation was sent, and a plain "saved" for update).
     */
    public function successMessage(string $key, ?string $context = null): static
    {
        if ($context === null) {
            $this->successMessage = $key;
        } else {
            $this->contextSuccessMessages[$context] = $key;
        }

        return $this;
    }

    /**
     * Name the client hook that runs after a create or update was saved (registered with the client action hooks).
     *
     * @throws \InvalidArgumentException on an empty hook name
     */
    public function after(string $hookName): static
    {
        if ($hookName === '') {
            throw new \InvalidArgumentException('Form after hook name must not be empty.');
        }
        $this->afterHook = $hookName;

        return $this;
    }

    /**
     * Set form drawer label translation keys keyed by slot.
     *
     * @param  array<string, string>  $keys  slots: title, hint, submit, required (client-side required message)
     *
     * @throws \InvalidArgumentException on an unknown slot or a non-string / empty key
     */
    public function labels(array $keys): static
    {
        foreach ($keys as $slot => $key) {
            if (! in_array($slot, self::LABEL_SLOTS, true)) {
                throw new \InvalidArgumentException("Unknown form label slot [{$slot}]; allowed: ".implode(', ', self::LABEL_SLOTS).'.');
            }
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException("Form label [{$slot}] must be a non-empty translation key.");
            }
        }
        $this->labels = $keys;

        return $this;
    }

    /**
     * Form label translation keys keyed by slot.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * Field names declared for a context, or null when the context is not declared.
     *
     * @return array<int, string>|null
     */
    public function fieldsFor(string $context): ?array
    {
        return $this->contexts[$context] ?? null;
    }

    /**
     * Declared contexts, in declaration order.
     *
     * @return array<int, string>
     */
    public function contexts(): array
    {
        return array_keys($this->contexts);
    }

    /** Create endpoint route name. */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /** Create endpoint HTTP method. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** Update endpoint route name. */
    public function getUpdateRouteName(): ?string
    {
        return $this->updateRouteName;
    }

    /** Update endpoint HTTP method. */
    public function getUpdateMethod(): string
    {
        return $this->updateMethod;
    }

    /** Permission ability of the form. */
    public function getAbility(): ?string
    {
        return $this->ability;
    }

    /** Success message translation key of `$context` (its own key, else the default), or the default without a context. */
    public function getSuccessMessage(?string $context = null): ?string
    {
        if ($context !== null && isset($this->contextSuccessMessages[$context])) {
            return $this->contextSuccessMessages[$context];
        }

        return $this->successMessage;
    }

    /** Client hook name run after a successful save, if any. */
    public function getAfter(): ?string
    {
        return $this->afterHook;
    }
}
