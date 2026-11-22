<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;

/**
 * Root registrar contract for Target Context declarations. Bound by
 * default to App\Support\Widget\NullWidgetExtensionRegistrar (harmless
 * no-op) so a project's Page/Post target adapter can always safely
 * register even when cms/Widget is disabled — no target metadata is
 * resolved in that state. The cms/Widget module rebinds this to
 * WidgetTargetContextRegistry in boot().
 */
interface WidgetTargetContextRegistrar
{
    public function registerTargetContext(WidgetTargetContextDefinition $definition): void;
}
