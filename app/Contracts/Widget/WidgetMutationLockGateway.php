<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

/**
 * Core/root-owned generic marker gateway for legacy-row mutation locks.
 * Reads/writes the stable Core-owned lock marker on Widget/Theme/Input
 * rows. Safe Import (post-MVP) writes through this contract; Core never
 * reads a module-owned table to enforce legacy-row locks, and this
 * contract's default implementation keeps protecting locked rows even if
 * cms/Widget is later disabled.
 *
 * $table is one of the stable Core table names (`widgets`, `widget_themes`,
 * `widget_inputs`); $id is the row's numeric primary key.
 */
interface WidgetMutationLockGateway
{
    public function isLocked(string $table, int $id): bool;

    public function lock(string $table, int $id, string $reason): void;

    public function unlock(string $table, int $id): void;
}
