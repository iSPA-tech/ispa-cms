<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Interfaces\AuditLogInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Interfaces\EnumerateInterface;
use Cms\Core\Interfaces\EnumerateTypeInterface;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\PermissionInterface;
use Cms\Core\Interfaces\RoleInterface;
use Cms\Core\Interfaces\SettingInterface;
use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Interfaces\UserInterface;
use Cms\Core\Repositories\AuditLogRepository;
use Cms\Core\Repositories\CategoryRepository;
use Cms\Core\Repositories\CategoryTargetRepository;
use Cms\Core\Repositories\EnumerateRepository;
use Cms\Core\Repositories\EnumerateTypeRepository;
use Cms\Core\Repositories\LanguageRepository;
use Cms\Core\Repositories\PermissionRepository;
use Cms\Core\Repositories\RoleRepository;
use Cms\Core\Repositories\SettingRepository;
use Cms\Core\Repositories\TranslateRepository;
use Cms\Core\Repositories\UploadRepository;
use Cms\Core\Repositories\UserRepository;

test('bindings resolve to expected repositories', function () {
    expect($this->app->make(AuditLogInterface::class))->toBeInstanceOf(AuditLogRepository::class);
    expect($this->app->make(CategoryInterface::class))->toBeInstanceOf(CategoryRepository::class);
    expect($this->app->make(CategoryTargetInterface::class))->toBeInstanceOf(CategoryTargetRepository::class);
    expect($this->app->make(EnumerateInterface::class))->toBeInstanceOf(EnumerateRepository::class);
    expect($this->app->make(EnumerateTypeInterface::class))->toBeInstanceOf(EnumerateTypeRepository::class);
    expect($this->app->make(LanguageInterface::class))->toBeInstanceOf(LanguageRepository::class);
    expect($this->app->make(PermissionInterface::class))->toBeInstanceOf(PermissionRepository::class);
    expect($this->app->make(RoleInterface::class))->toBeInstanceOf(RoleRepository::class);
    expect($this->app->make(SettingInterface::class))->toBeInstanceOf(SettingRepository::class);
    expect($this->app->make(TranslateInterface::class))->toBeInstanceOf(TranslateRepository::class);
    expect($this->app->make(UploadInterface::class))->toBeInstanceOf(UploadRepository::class);
    expect($this->app->make(UserInterface::class))->toBeInstanceOf(UserRepository::class);
});
