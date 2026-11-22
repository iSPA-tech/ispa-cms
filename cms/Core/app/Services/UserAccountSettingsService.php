<?php

namespace Cms\Core\Services;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Services\Security\AdminMfaService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UserAccountSettingsService
{
    public function __construct(
        private readonly LanguageInterface $languageModel,
        private readonly AdminMfaService $adminMfaService,
    ) {
    }

    public function forUser(User $user): array
    {
        $settings = UserType::SETTING_DEFAULT;
        $storedMfaMode = false;
        $storedLegacyTwoFa = false;

        foreach ($user->settings()->get() as $setting) {
            $group = (string) $setting->setting_group;
            $key = (string) $setting->setting_key;

            if (!isset($settings[$group][$key]) || !is_array($setting->setting_value)) {
                continue;
            }

            $settings[$group][$key] = array_merge(
                $settings[$group][$key],
                $setting->setting_value,
            );

            $storedMfaMode = $storedMfaMode || ($group === 'security' && $key === 'mfa_mode');
            $storedLegacyTwoFa = $storedLegacyTwoFa || ($group === 'security' && $key === 'two_fa');
        }

        if (!$storedMfaMode && $storedLegacyTwoFa && $settings['security']['two_fa']['value'] === true) {
            $settings['security']['mfa_mode']['value'] = AdminMfaService::MODE_EMAIL_ONLY;
        }

        $settings['security']['mfa_mode']['options'] = $this->adminMfaService->modeOptions();
        $settings['security']['two_fa']['value'] = in_array(
            $settings['security']['mfa_mode']['value'],
            [AdminMfaService::MODE_EMAIL_ONLY, AdminMfaService::MODE_EMAIL_PLUS_TOTP],
            true,
        );

        $languages = $this->languageModel->get([
            'select' => ['id', 'name', 'path'],
            'where' => ['status' => 'published'],
        ]);

        $settings['localization']['language']['options'] = collect($languages)
            ->map(static fn ($language): array => [
                'id' => $language->id,
                'name' => $language->name,
                'path' => $language->path,
            ])
            ->values()
            ->all();
        $settings['localization']['timezone']['options'] = timezone_identifiers_list();

        return $settings;
    }

    public function update(User $user, array $payload): array
    {
        return DB::transaction(function () use ($user, $payload): array {
            $canonical = [
                'localization' => [
                    'language' => 'select',
                    'timezone' => 'select',
                ],
                'security' => [
                    'email_change_password' => 'boolean',
                ],
            ];

            foreach ($canonical as $group => $keys) {
                foreach ($keys as $key => $type) {
                    $valuePath = $group . '.' . $key . '.value';
                    if (!Arr::has($payload, $valuePath)) {
                        continue;
                    }

                    $value = data_get($payload, $valuePath);

                    $user->settings()->updateOrCreate(
                        [
                            'setting_group' => $group,
                            'setting_key' => $key,
                        ],
                        [
                            'setting_value' => [
                                'value' => $value,
                                'type' => $type,
                            ],
                        ],
                    );
                }
            }

            $mfaMode = data_get($payload, 'security.mfa_mode.value');
            if ($mfaMode === null && Arr::has($payload, 'security.two_fa.value')) {
                $mfaMode = data_get($payload, 'security.two_fa.value')
                    ? AdminMfaService::MODE_EMAIL_ONLY
                    : AdminMfaService::MODE_OFF;
            }
            if ($mfaMode !== null) {
                $this->adminMfaService->persistMfaMode($user, (string) $mfaMode);
                $user->settings()
                    ->where('setting_group', 'security')
                    ->where('setting_key', 'mfa_mode')
                    ->update([
                        'setting_value' => [
                            'value' => (string) $mfaMode,
                            'type' => 'select',
                        ],
                    ]);
            }

            return $this->forUser($user->fresh());
        });
    }
}
