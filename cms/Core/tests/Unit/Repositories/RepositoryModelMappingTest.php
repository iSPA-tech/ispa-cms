<?php

uses(\Cms\Core\Tests\TestCase::class);

it('repositories map to expected models', function (string $repositoryClass, string $modelClass) {
    $repo = new $repositoryClass();

    expect($repo->getModelClass())->toBe($modelClass);
    expect($repo->model())->toBeInstanceOf($modelClass);
})->with([
    [\Cms\Core\Repositories\AuditLogRepository::class, \Cms\Core\Models\AuditLog::class],
    [\Cms\Core\Repositories\CategoryRepository::class, \Cms\Core\Models\Category::class],
    [\Cms\Core\Repositories\CategoryTargetRepository::class, \Cms\Core\Models\CategoryTarget::class],
    [\Cms\Core\Repositories\EmailLogRepository::class, \Cms\Core\Models\EmailLog::class],
    [\Cms\Core\Repositories\EmailRepository::class, \Cms\Core\Models\Email::class],
    [\Cms\Core\Repositories\EmailTemplateRepository::class, \Cms\Core\Models\EmailTemplate::class],
    [\Cms\Core\Repositories\EnumerateRepository::class, \Cms\Core\Models\Enumerate::class],
    [\Cms\Core\Repositories\EnumerateTypeRepository::class, \Cms\Core\Models\EnumerateType::class],
    [\Cms\Core\Repositories\FieldItemRepository::class, \Cms\Core\Models\FieldItem::class],
    [\Cms\Core\Repositories\FieldRepository::class, \Cms\Core\Models\Field::class],
    [\Cms\Core\Repositories\LanguageRepository::class, \Cms\Core\Models\Language::class],
    [\Cms\Core\Repositories\MenuItemRepository::class, \Cms\Core\Models\MenuItem::class],
    [\Cms\Core\Repositories\MenuRepository::class, \Cms\Core\Models\Menu::class],
    [\Cms\Core\Repositories\MetaAttributeRepository::class, \Cms\Core\Models\MetaAttribute::class],
    [\Cms\Core\Repositories\MetaClassRepository::class, \Cms\Core\Models\MetaClass::class],
    [\Cms\Core\Repositories\MetaDataRepository::class, \Cms\Core\Models\MetaData::class],
    [\Cms\Core\Repositories\MetaValueBigNumRepository::class, \Cms\Core\Models\MetaValueBigNum::class],
    [\Cms\Core\Repositories\MetaValueBooleanRepository::class, \Cms\Core\Models\MetaValueBoolean::class],
    [\Cms\Core\Repositories\MetaValueDateRepository::class, \Cms\Core\Models\MetaValueDate::class],
    [\Cms\Core\Repositories\MetaValueStringRepository::class, \Cms\Core\Models\MetaValueString::class],
    [\Cms\Core\Repositories\MetaValueTextRepository::class, \Cms\Core\Models\MetaValueText::class],
    [\Cms\Core\Repositories\MetaValueTinyNumRepository::class, \Cms\Core\Models\MetaValueTinyNum::class],
    [\Cms\Core\Repositories\PermissionRepository::class, \Cms\Core\Models\Permission::class],
    [\Cms\Core\Repositories\RoleRepository::class, \Cms\Core\Models\Role::class],
    [\Cms\Core\Repositories\SettingRepository::class, \Cms\Core\Models\Setting::class],
    [\Cms\Core\Repositories\StorageRepository::class, \Cms\Core\Models\Storage::class],
    [\Cms\Core\Repositories\TranslateRepository::class, \Cms\Core\Models\Translate::class],
    [\Cms\Core\Repositories\UploadRepository::class, \Cms\Core\Models\Upload::class],
    [\Cms\Core\Repositories\UserRepository::class, \App\Models\User::class],
    [\Cms\Core\Repositories\WidgetInputRepository::class, \Cms\Core\Models\WidgetInput::class],
    [\Cms\Core\Repositories\WidgetRepository::class, \Cms\Core\Models\Widget::class],
    [\Cms\Core\Repositories\WidgetThemeRepository::class, \Cms\Core\Models\WidgetTheme::class],
]);
