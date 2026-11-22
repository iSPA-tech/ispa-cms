<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Throwable;

/**
 * The widget hub could not be read: not configured, transport failure, bad status, bad response signature or an
 * answer without the expected shape. Widgets already on the site keep working.
 */
final class WidgetHubUnreachable extends WidgetHubException
{
    public const ERROR_CODE = 'WIDGET_HUB_UNREACHABLE';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::ERROR_CODE, 503, 'Chưa kết nối được kho widget. Widget trên site vẫn dùng bình thường.', $previous);
    }
}
