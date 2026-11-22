<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The widget is emergency-blocked (Core `Widget::isBlocked()`), so its manifest is never approved
 * automatically. Renders itself as the frozen 409 WIDGET_BLOCKED envelope; callers holding an
 * idempotency claim must release it before letting this exception escape.
 */
final class WidgetBlockedException extends RuntimeException
{
    public function __construct(public readonly int $widgetId)
    {
        parent::__construct('This widget is blocked and cannot be used until it is unblocked.');
    }

    public function render(): JsonResponse
    {
        $response = WidgetApiErrorResponse::jsonResponse(WidgetApiErrorResponse::WIDGET_BLOCKED, $this->getMessage());

        // The Widget envelope carries `code`; `error_code` is added for the app-wide convention the
        // Part 2 plan relies on ("Error responses carry `error_code`").
        $data = $response->getData(true);
        $data['error_code'] = WidgetApiErrorResponse::WIDGET_BLOCKED;

        return $response->setData($data);
    }
}
