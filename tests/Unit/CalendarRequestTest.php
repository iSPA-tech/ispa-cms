<?php

namespace Tests\Unit;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Http\Requests\Calendar\StoreCalendarRequest;
use Cms\Core\Http\Requests\Calendar\UpdateCalendarRequest;
use Tests\TestCase;

class CalendarRequestTest extends TestCase
{
    public function test_calendar_requests_validate_language_and_status_values(): void
    {
        $storeRules = (new StoreCalendarRequest())->rules();
        $updateRules = (new UpdateCalendarRequest())->rules();

        $this->assertSame(
            'required|integer|exists:' . CoreTableConstant::LANGUAGES . ',id',
            $storeRules['language_id']
        );
        $this->assertSame(
            'nullable|string|max:50|exists:' . CoreTableConstant::ENUMERATES . ',enum_key',
            $storeRules['status']
        );
        $this->assertSame(
            'sometimes|required|integer|exists:' . CoreTableConstant::LANGUAGES . ',id',
            $updateRules['language_id']
        );
        $this->assertSame(
            'sometimes|nullable|string|max:50|exists:' . CoreTableConstant::ENUMERATES . ',enum_key',
            $updateRules['status']
        );
    }
}
