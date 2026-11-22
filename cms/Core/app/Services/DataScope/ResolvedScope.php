<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

/**
 * The data scope a user holds for one permission key, as computed by ScopeResolver.
 *
 * Visible rows = everything when `all`; otherwise the union of the `own` predicate (when set) and, per dimension,
 * the nodes of that dimension's ExpansionPlan.
 */
final readonly class ResolvedScope
{
    /**
     * @param bool $all Whether the scope covers every row.
     * @param bool $own Whether rows owned by the user are visible.
     * @param array<string, ExpansionPlan> $plans Expansion plan per dimension name.
     */
    public function __construct(
        public bool $all,
        public bool $own,
        public array $plans,
    ) {
    }

    /**
     * A scope that sees nothing.
     */
    public static function none(): self
    {
        return new self(false, false, []);
    }

    /**
     * A scope that sees every row.
     */
    public static function everything(): self
    {
        return new self(true, false, []);
    }

    /**
     * Whether the scope sees nothing: not `all`, not `own`, and no plan with a self node or an anchor.
     */
    public function isEmpty(): bool
    {
        if ($this->all || $this->own) {
            return false;
        }

        foreach ($this->plans as $plan) {
            if ($plan->selfNodeIds !== [] || $plan->anchorIds !== []) {
                return false;
            }
        }

        return true;
    }
}
