<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;

/**
 * A single registered `{key, version}` provider contract (adr-widget-
 * provider-lifecycle.md decision 1). Every provider — including the
 * legacy `product.latest`/`product.featured`/`product.hot`/`post.latest`/
 * `banner.main_slide`/`banner.popup_ads` compatibility adapters — is a
 * class implementing this contract, never a hardcoded renderer branch.
 * There is no default binding for this interface: implementations are
 * registered instances, not a container-resolved singleton.
 */
interface WidgetDataProvider
{
    public function key(): string;

    public function version(): int;

    /**
     * @param array<string, mixed> $arguments
     */
    public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult;
}
