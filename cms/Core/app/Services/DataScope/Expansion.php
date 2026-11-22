<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

/**
 * How far a held hierarchy node reaches.
 *
 * - SelfOnly: the node itself.
 * - Descendants: the node and its whole subtree.
 * - Branch: the subtree of the node's parent, unless that parent is a root (see ExpansionPlanner).
 */
enum Expansion: string
{
    case SelfOnly = 'self';
    case Descendants = 'descendants';
    case Branch = 'branch';
}
