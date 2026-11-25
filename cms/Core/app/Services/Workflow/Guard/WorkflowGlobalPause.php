<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Guard;

use App\Models\User;
use Cms\Core\Models\Setting;
use Throwable;

/**
 * One switch that pauses every workflow of the site (roadmap B7). While it is on, no run may start (lane 3 checks
 * {@see self::active()} in every run start); runs already waiting stay where they are.
 *
 * On when the server config `core.workflow_guard.paused_all` (env WORKFLOW_PAUSED_ALL) is true, or when the Webmaster
 * turned on the setting `workflow.paused_all` (admin API `PUT workflow/pause-all`, command
 * `cms:workflow-pause-all on|off`). Every change of the setting is audited and alerted. The generic settings endpoints
 * never write the key (SettingController::PROTECTED_KEYS).
 *
 * @package Cms\Core\Services\Workflow\Guard
 * @since   2026-11-24
 */
class WorkflowGlobalPause
{
    public const SETTING_KEY = 'workflow.paused_all';

    public function __construct(private readonly WorkflowChangeAlerter $alerter)
    {
    }

    public function active(): bool
    {
        return $this->forcedByServer() || $this->settingOn();
    }

    public function forcedByServer(): bool
    {
        return (bool) config('core.workflow_guard.paused_all', false);
    }

    public function settingOn(): bool
    {
        try {
            $value = Setting::query()->where('setting_key', self::SETTING_KEY)->first()?->value;
        } catch (Throwable) {
            return false;
        }

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    /**
     * Turn the Webmaster's switch on or off. Returns false when it already had that value (nothing audited).
     */
    public function set(bool $on, ?User $actor, string $source = 'admin'): bool
    {
        $before = $this->settingOn();
        if ($before === $on) {
            return false;
        }

        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'text',
            'group_name' => 'workflow',
            'name' => 'paused_all',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'Pause all workflows'],
            'value' => $on ? '1' : '0',
        ])->save();

        $userId = $actor?->getKey();
        WorkflowAudit::log(
            WorkflowAudit::GLOBAL_PAUSE_CHANGED,
            ['from' => $before, 'to' => $on, 'source' => $source],
            Setting::class,
            (int) $setting->getKey(),
            $on ? 'danger' : 'success',
            is_numeric($userId) ? (int) $userId : null,
        );
        $this->alerter->notify('global_pause_changed', null, ['paused' => $on, 'reason' => $source], $actor);

        return true;
    }
}
