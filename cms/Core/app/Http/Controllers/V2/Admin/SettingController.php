<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Throwable;
use Exception;
use JsonException;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Environment\EnvironmentSettingService;
use Cms\Core\Helpers\RedisHelper;
use App\Constants\CacheConstants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Interfaces\SettingInterface;
use Illuminate\Database\Eloquent\Builder;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Settings\EnvBoundSettings;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Http\Requests\Setting\StoreSettingRequest;
use Cms\Core\Http\Requests\Setting\UpdateHomepageSettingRequest;

/**
 * IMPORTANT – DO NOT CHANGE VALUE UPDATE FLOW
 *
 * This controller intentionally updates `settings.value`
 * ONLY via Eloquent model assignment + save().
 *
 * Reason:
 * - `settings.value` uses EncryptedSettingValue cast
 * - Encryption/decryption ONLY works when Eloquent mutators are triggered
 * - Query builder updates (where()->update()) WILL BYPASS casts
 *   and will store plaintext secrets into database.
 *
 * Security policy:
 * - Settings with input_setting.isEnv = true OR isPublic = false
 *   MUST be stored encrypted in DB
 * - Admin API returns the decrypted value for admin UI usage, except real secrets
 *   (see SecretGuard::isMaskedSetting()) which are masked as '********' and write-only
 *
 * ⚠️ NEVER refactor value updates to:
 *   - DB::table()->update()
 *   - Model::query()->update()
 *   - bulk update shortcuts
 *
 * Any change here MUST preserve:
 * - Encrypted storage
 * - Decrypted admin response
 * - No accidental plaintext persistence
 *
 * Manage core application settings for Admin v2: list, update,
 * synchronize configuration to files/cache, and provide structured
 * settings payloads for the admin UI.
 *
 * @see SettingInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class SettingController extends AdminController
{
    /**
     * Setting keys the generic endpoints never write: each has its own endpoint with its own permission check and
     * audit. `modules.install_enabled` belongs to the Webmaster-only module install switch
     * (ModuleInstallSettingController), which also checks the server and hub locks and prepares the site.
     *
     * @var list<string>
     */
    public const PROTECTED_KEYS = [
        ModuleInstallPolicy::SETTING_KEY,
        // The workflow outbound host list belongs to WorkflowHttpHostsController (Webmaster only, audited).
        \Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist::SETTING_KEY,
        // Pause all workflows belongs to WorkflowGuardController / cms:workflow-pause-all (Webmaster, audited, alerted).
        \Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause::SETTING_KEY,
        // Who reviews workflow changes belongs to WorkflowGuardController (Webmaster, audited, alerted).
        \Cms\Core\Services\Workflow\Guard\WorkflowChangeReviewers::SETTING_KEY,
    ];

    /** Message for a refused write to a protected key. */
    private const PROTECTED_MESSAGE = 'This setting is changed on its own screen, not here.';

    /**
     * Controller constructor
     *
     * @param SettingInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected SettingInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Quickly update homepage settings.
     *
     * @param int $id Setting identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-07-29, 19:21 ICT
     */
    public function quickUpdate(int $id): mixed
    {
        if (!user()?->can('update', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (request()->boolean('clear')) {
            return $this->clearSecret($id);
        }

        try {
            $data = request()->all();
            $newValue = formatValueByInputType(
                $data['value'],
                $data['type']
            );

            $setting = $this->model->find($id);
            if (!$setting) {
                return ires()->error()->message(__('mgs.id.404'));
            }
            if ($this->isProtectedKey($setting->setting_key)) {
                return ires()->error()->code(403)->message(self::PROTECTED_MESSAGE);
            }
            // The theme folder is read when the site is built: it changes through `cms:env-set` and a deploy.
            if (EnvBoundSettings::isBootKey((string)$setting->setting_key)) {
                return ires()->error()->code(422)->message(__('text.setting.theme_read_only'));
            }

            $inputSetting = is_array($setting->input_setting)
                ? $setting->input_setting
                : (array)json_decode($setting->input_setting ?? '{}', true);

            if ($this->isMaskedSetting($inputSetting, $setting->type, $setting->setting_key, $setting->name) && ($newValue === '********' || $newValue === '')) {
                return ires()->message('Updated successfully');
            }

            // SECURITY: must trigger cast (EncryptedSettingValue)
            $setting->value = $newValue;
            // 🚫 AGENT WARNING:
            // DO NOT replace this with query()->update() or repository update shortcuts.
            // We REQUIRE Eloquent save() to trigger EncryptedSettingValue cast (encrypt at rest).
            $setting->save();

            // Workers keep the config they booted with: restart them after an env-bound change (EnvBoundSettings).
            $this->synchronizeSettingFile(
                false,
                str_starts_with($setting->setting_key, 'email.')
                    || EnvBoundSettings::isEnvBound((string)$setting->setting_key, $inputSetting)
            );
        } catch (Exception $e) {
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
                'url' => request()->fullUrl(),
            ]);
            return ires()->error()->message($e->getMessage());
        }

        return ires()->message('Updated successfully');
    }

    /**
     * Empty a stored secret (quick update with `clear: true`).
     *
     * A plain quick update can only replace a secret ('' and '********' keep the stored value), so emptying one
     * needs this explicit flag. Only masked settings qualify; anything else is refused with 422. Env-bound rows go
     * through EnvironmentSettingService::clear() (row, `.env` line, caches, audit); other rows are emptied through
     * Eloquent save() and audited here. The value never reaches the response, a log or the audit entry.
     *
     * @param int $id Setting identifier.
     *
     * @return mixed
     */
    private function clearSecret(int $id): mixed
    {
        $setting = $this->model->find($id);
        if (!$setting) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $key = (string)$setting->setting_key;
        if ($this->isProtectedKey($key)) {
            return ires()->error()->code(403)->message(self::PROTECTED_MESSAGE);
        }

        $inputSetting = is_array($setting->input_setting)
            ? $setting->input_setting
            : (array)json_decode($setting->input_setting ?? '{}', true);

        if (!$this->isMaskedSetting($inputSetting, $setting->type, $key, $setting->name)) {
            return ires()->error()->code(422)->message('Only a secret setting can be cleared.');
        }

        try {
            $environment = app(EnvironmentSettingService::class);
            if ($environment->isAllowed($key)) {
                $environment->clear($key, (int)user('id'));
            } else {
                // SECURITY: Eloquent save() keeps the EncryptedSettingValue cast in the write path.
                $setting->value = null;
                $setting->save();

                $this->synchronizeSettingFile(false, str_starts_with($key, 'email.'));

                event(new AuditLogEvent(
                    'setting.secret.clear',
                    ['key' => $key, 'secret' => true],
                    $this->model->model()::class,
                    (int)$setting->id
                ));
            }
        } catch (Throwable $e) {
            // The original exception may carry the value (SQL bindings), so neither it nor its message is kept.
            CMSLogger::error([
                'message' => 'Clearing a secret setting failed',
                'key' => $key,
                'exception_class' => $e::class,
            ]);

            return ires()->error()->message('Clearing the setting failed.');
        }

        return ires()->data(['setting_key' => $key, 'has_value' => false])->message('Cleared successfully');
    }

    /**
     * Whether $key (or a language / enumerate variant of it) is a protected key ({@see self::PROTECTED_KEYS}).
     */
    private function isProtectedKey(?string $key): bool
    {
        foreach (self::PROTECTED_KEYS as $protected) {
            if ($key === $protected || str_starts_with((string)$key, $protected . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a setting must be masked (write-only) by the admin API.
     *
     * Delegates to SecretGuard::isMaskedSetting(): type=password, input_setting secret=true, or a secret-looking
     * setting key / env variable name. Env-managed (isEnv) or non-public settings that are not real secrets stay
     * readable and editable. The list masks masked values as '********' and an incoming '********' or ''
     * keeps the stored value.
     *
     * @param array<string, mixed> $inputSetting Decoded input_setting of the row.
     * @param string|null $type Setting type (e.g. 'password').
     * @param string|null $settingKey Setting key (e.g. 'email.MAIL_PASSWORD').
     * @param string|null $name Setting name (env variable name for env-managed rows).
     *
     * @return bool
     */
    private function isMaskedSetting(array $inputSetting, ?string $type = null, ?string $settingKey = null, ?string $name = null): bool
    {
        return app(SecretGuard::class)->isMaskedSetting([
            'type' => $type,
            'setting_key' => $settingKey,
            'name' => $name,
            'input_setting' => $inputSetting,
        ]);
    }

    /**
     * Save multiple settings in a batch.
     *
     * @return mixed
     *
     * @throws Throwable
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function save(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $data = request()->except('sync');

        //  Pre-pair for edit env file
        //         // This code path is SECURITY-SENSITIVE.
        // Do NOT modify update strategy unless you fully understand
        // Eloquent casts + encrypted setting lifecycle.
        $envBoundChanged = false;
        DB::transaction(function () use ($data, &$envBoundChanged) {
            foreach ($data as $item) {
                if ($item) {
                    foreach ($item as $iKey => $value) {
                        // Protected keys are skipped (not refused): the settings page may send every row back.
                        // Build and boot keys (the theme folder) too: `.env` is their source and they change only
                        // through `cms:env-set` and a deploy (EnvBoundSettings).
                        if ($this->isProtectedKey((string)$iKey) || EnvBoundSettings::isBootKey((string)$iKey)) {
                            continue;
                        }

                        $setting = $this->model->findBy(['setting_key' => $iKey]);

                        if (!$setting) {
                            continue;
                        }

                        $inputSetting = is_array($setting->input_setting)
                            ? $setting->input_setting
                            : (array)json_decode($setting->input_setting ?? '{}', true, 512, JSON_THROW_ON_ERROR);

                        if ($this->isMaskedSetting($inputSetting, $setting->type, $setting->setting_key, $setting->name) && ($value === '********' || $value === '')) {
                            continue;
                        }

                        // Plain value before the save (an encrypted value gets a new ciphertext on every save, so the
                        // stored column always looks changed).
                        $before = $setting->value;

                        $setting->value = $value;
                        // 🚫 AGENT WARNING:
                        // DO NOT replace this with query()->update() or repository update shortcuts.
                        // We REQUIRE Eloquent save() to trigger EncryptedSettingValue cast (encrypt at rest).
                        $setting->save();

                        if (EnvBoundSettings::isEnvBound((string)$setting->setting_key, $inputSetting)
                            && self::plainValue($before) !== self::plainValue($value)) {
                            $envBoundChanged = true;
                        }
                    }
                }
            }
        });

        // Queue workers keep the config they booted with (mail, Mailgun, Telegram logger, ...): restart them when an
        // env-bound setting changed, since the save no longer goes through `.env` and a config rebuild.
        $this->synchronizeSettingFile(
            restartQueue: $envBoundChanged || (isset($data['email']) && is_array($data['email']) && $data['email'] !== [])
        );

        return ires()->message(__('Đã cập nhật thông tin Cài đặt thành công!'));
    }

    /**
     * Compare form of a setting value: booleans as 'true'/'false', null as '', other scalars as strings.
     */
    private static function plainValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value),
        };
    }

    /**
     * Synchronize settings to disk and optionally update environment values.
     *
     * Executes artisan tasks to initialize settings, export seeders, and clears
     * cached settings.
     *
     * @param bool $isUpdateEnv Whether to update the .env file.
     *
     * @return void
     *
     * @author malayvuong
     * @since  7.160.1175 - 2025-04-03, 14:47 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    protected function synchronizeSettingFile(
        bool $isUpdateEnv = true,
        bool $restartQueue = false
    ): void
    {
        Artisan::call('cms:setting-initialize --updateEnv=' . ($isUpdateEnv ? 'true' : 'false'));

        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::SETTINGS);
        //  end file env

        //  Remove cache
        RedisHelper::getInstance()->del(CacheConstants::CACHE_SETTINGS);

        //  cms:setting-initialize only rewrites the runtime `private` scope;
        //  drop all three runtime scopes so `env`/`public` reads re-warm too.
        app(CMSSettingRuntime::class)->forget();

        if ($restartQueue) {
            Artisan::call('queue:restart');
        }
    }

    /**
     * Update existing setting entries.
     *
     * @param int $id Setting identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-19, 12:14
     */
    public function update(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $data = request()->all();

        $newKey = request('group_name') . '.' . request('name');
        $currentKeys = $this->model->model()->newQuery()
            ->whereIn('id', array_column((array)($data['list'] ?? []), 'id'))
            ->pluck('setting_key')->all();
        foreach ([$newKey, ...$currentKeys] as $key) {
            if ($this->isProtectedKey((string)$key)) {
                return ires()->error()->code(403)->message(self::PROTECTED_MESSAGE);
            }
        }

        foreach ($data['list'] as $value) {
            $update = [
                'input_setting' => request('input_setting'),
                'group_name' => request('group_name'),
                'name' => request('name'),
                'type' => request('type'),
            ];
            $key = request('group_name') . '.' . request('name');
            if (isset($value['language_id'])) {
                $key .= '.' . $value['language_id'];
            }
            $update['setting_key'] = $key;

            $this->model->update(['id' => $value['id']], $update);
        }

        return ires()->message('success');
    }

    /**
     * Update homepage settings stored in the environment file.
     *
     * @param UpdateHomepageSettingRequest $request Validated request data.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-04-03, 14:47 ICT
     */
    public function updateHomePageSetting(UpdateHomepageSettingRequest $request): mixed
    {
        try {
            $data = $request->validated();

            //  Write to env file
            $envData = [
                'HOMEPAGE_TYPE' => $data['homepage'],
                'HOMEPAGE_STATIC_ID' => $data['static_id'],
            ];

            //  Update env file
            $envFilePath = base_path('.env');
            $envFileContent = file_get_contents($envFilePath);
            //  Replace the existing values with new ones, if it's not exists the key, it will be added
            foreach ($envData as $key => $value) {
                $pattern = "/^$key=(.*)$/m";
                if (preg_match($pattern, $envFileContent)) {
                    $envFileContent = preg_replace($pattern, "$key=\"$value\"", $envFileContent);
                } else {
                    $envFileContent .= "\n$key=\"$value\"";
                }
            }
            file_put_contents($envFilePath, $envFileContent);
        } catch (Exception $e) {
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return ires()->message('Updated homepage settings successfully');
    }

    /**
     * Retrieve current homepage configuration.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-04-03, 14:40 ICT
     */
    public function getHomePageSetting(): mixed
    {
        return ires()->data([
            'homepage' => config('ispa-cms.homepage', 'default'),
            'static_id' => config('ispa-cms.homepage_static_id'),
        ]);
    }

    /**
     * Store a new setting defined by an administrator.
     *
     * @param StoreSettingRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-18, 15:57
     */
    public function store(StoreSettingRequest $request): mixed
    {
        if (!user()?->can('create', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($this->isProtectedKey(request('group_name') . '.' . request('name'))) {
            return ires()->error()->code(403)->message(self::PROTECTED_MESSAGE);
        }

        DB::beginTransaction();

        try {
            if ((bool)request('input_setting')['language'] === true) {
                foreach (request('languages') as $lang) {
                    $model = $this->fill(null, $lang['id']);
                    // 🚫 AGENT WARNING:
                    // DO NOT replace this with query()->update() or repository update shortcuts.
                    // We REQUIRE Eloquent save() to trigger EncryptedSettingValue cast (encrypt at rest).
                    $model->save();
                }
            } else {
                $model = $this->fill();
                // 🚫 AGENT WARNING:
                // DO NOT replace this with query()->update() or repository update shortcuts.
                // We REQUIRE Eloquent save() to trigger EncryptedSettingValue cast (encrypt at rest).
                $model->save();
            }

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::SETTINGS);

        return ires()->message('done');
    }

    /**
     * Fill table
     *
     * Hydrate a Setting model instance with request data and build a unique key.
     *
     * @param mixed|null $model Existing model or null to create a new one.
     * @param int $langId Language identifier for localization (0 = none).
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    protected function fill(mixed $model = null, int $langId = 0): mixed
    {
        if (!$model) {
            $model = $this->model->reset()->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if ($model->id > 0) {
        } else {
            $key = request('group_name') . '.' . request('name');
            if ((int)request('enumerate_id', 0) > 0) {
                $key .= '.e:' . request('enumerate_id');
            }
            if ($langId > 0) {
                $key .= '.' . $langId;
            } else if (request('language_id', 0) > 0) {
                $key .= '.' . request('language_id');
            }
            $model->setting_key = $key;
        }

        if (request('enumerate_id', 0) === 0) {
            $model->enumerate_id = null;
        }
        if (request('language_id', 0) === 0) {
            $model->language_id = null;
        }
        if ($langId > 0) {
            $model->language_id = $langId;
        }

        return $model;
    }

    /**
     * Display a listing of settings.
     *
     * @return mixed
     *
     * @throws JsonException
     *
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'order' => ['id' => 'desc'],
            'page' => 1000,
            'with' => [
                'language:id,name,path,flag',
            ],
            'when' => [
                [
                    request('type') !== null,
                    function (Builder $q): Builder {
                        $keys = explode(',', request('type', 'basic'));

                        return $q->whereIn('group_name', $keys);
                    },
                ],
            ],
        ]);

        $data = [];
        foreach ($model as $item) {
            $value = $item->value;

            if ($item->type === 'image') {
                $value = $this->decodeImageValue($value);
            } else {
                if ($item->type === 'select' && ($item->input_setting['multiple'] ?? false) === true) {
                    if (is_string($value)) {
                        $value = json_decode($value ?? '[]', false, 512, JSON_THROW_ON_ERROR);
                    }
                } else {
                    $value = formatValueByInputType(
                        $value,
                        $item->type
                    );
                    if ($item->type === 'boolean' && is_string($value) && in_array($value, ['true', 'false'], true)) {
                        $value = $value === 'true';
                    }
                }
            }

            $item->value = $value;
            //  decode input_setting if it's string into array
            if (is_string($item->input_setting)) {
                $item->input_setting = json_decode($item->input_setting, true, 512, JSON_THROW_ON_ERROR);
            }
            //  Ensure input_setting has default keys if not exists get from model default
            $item->input_setting = array_merge($this->model->model()->defaultInputSetting, (array)$item->input_setting);

            //  Mask secret values: never return the decrypted secret,
            //  only whether one is stored. Saving '********' or '' keeps the stored value.
            if ($this->isMaskedSetting($item->input_setting, $item->type, $item->setting_key, $item->name)) {
                $item->has_value = (string)$item->getRawOriginal('value') !== '';
                $item->value = $item->has_value ? '********' : '';
            }

            $data[] = $item;
        }

        //  Get template folder list
        $dirs = glob(base_path('projects/*'), GLOB_ONLYDIR);
        $templateDirs = [];
        foreach ($dirs as $template) {
            $explode = explode('/', $template);
            $templateDirs[] = $explode[(count($explode) - 1)];
        }

        return ires()->data(['data' => $data, 'template' => $templateDirs]);
    }

    /**
     * Decode image setting values that may be encrypted strings.
     */
    private function decodeImageValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
                // ignore and fall through
            }
        }

        return [];
    }

    /**
     * Convert an associative array to PHP short array syntax.
     *
     * @param array $array Source array to convert.
     *
     * @return string
     *
     * @author malayvuong
     * @since  7.160.1175 - 2025-04-03, 15:00 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    private function convertToShortArraySyntax(array $array)
    {
        $output = '[';
        foreach ($array as $key => $value) {
            $output .= "'$key' => ";
            if (is_array($value)) {
                $output .= $this->convertToShortArraySyntax($value) . ', ';
            } else {
                $output .= "'" . addslashes($value) . "', ";
            }
        }

        return rtrim($output, ', ') . ']';
    }
}
