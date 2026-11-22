<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Public;

use Cms\Core\Responses\AppResponse;

use JsonException;
use App\Constants\CacheConstants;
use App\Http\Controllers\Controller;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Cms\Core\Interfaces\MenuItemInterface;
use Throwable;

class StaticController extends Controller
{
    use \App\Http\Controllers\V3\Public\Concerns\SerializesPublicPayloadOnce;

    /**
     * StaticController constructor
     *
     * @param MenuInterface $menu
     * @param MenuItemInterface $item
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 23:26 ICT
     */
    public function __construct(
        protected MenuInterface $menu,
        protected MenuItemInterface $item,
        protected CaptchaServiceInterface $captcha,
    )
    {
        parent::__construct();
    }

    /**
     * Footer menu list
     *
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 23:25 ICT
     */
    public function footerMenu(): mixed
    {
        //  Process to get main menu
        $menu = $this->item->getMenuItemListByMenuGroup('footer_menu', config('ispa-cms.cache_enable', false));

        $payload = $this->serializeOnce($menu);
        $this->cachePublicPayload(CacheConstants::CACHE_FOOTER_MENU, $payload, $this->sharedPayloadTtl());

        return ires()->data($payload);
    }

    /**
     * Main menu list
     *
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 23:25 ICT
     */
    public function mainMenu(): mixed
    {
        //  Process to get main menu
        $menu = $this->item->getMenuItemListByMenuGroup('main_menu', config('ispa-cms.cache_enable', false));

        $payload = $this->serializeOnce($menu);
        $this->cachePublicPayload(CacheConstants::CACHE_MAIN_MENU, $payload, $this->sharedPayloadTtl());

        return ires()->data($payload);
    }

    /**
     * Get settings
     *
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 17:29 ICT
     */
    public function settings(): mixed
    {
        $keySupported = [
            'title' => 'setting.basic.title',
            'description' => 'setting.basic.description',
            'website_slogan' => 'setting.basic.website_slogan',
            'hashtag' => 'setting.basic.hashtag',
            'brand' => 'setting.basic.brand',
            'copy_right' => 'setting.basic.brand',
            'address' => 'setting.contact.address',
            'admin_email' => 'setting.contact.admin_email',
            'phone' => 'setting.contact.phone',
            'time_serves' => 'setting.contact.time_serves',
            'email_contact' => 'setting.contact.email_contact',
            'contribute_contact' => 'setting.contact.email_contribute',
            'representative_office' => 'setting.contact.representative_office',
            'facebook' => 'setting.social.url.facebook',
            'fb_app_id' => 'setting.tracking.chat.app-id-facebook',
            'youtube' => 'setting.social.url.youtube',
            'twitter' => 'setting.social.url.twitter',
            'instagram' => 'setting.social.url.instagram',
            'linkedin' => 'setting.social.url.linkedin',
            'skype' => 'setting.social.url.skype',
            'tiktok' => 'setting.social.url.tiktok',
            'zalo' => 'setting.social.url.zalo',
            'version' => 'setting.advanced.VERSION',
            'timezone' => 'setting.advanced.TIMEZONE',
            'editor' => 'setting.advanced.TEXT_EDITOR',
            'maintenance' => 'setting.advanced.MAINTENANCE_ENABLE',
            'widget' => 'setting.chat.chat.widget',
            'html' => 'setting.html.html-1',
            'html-footer' => 'setting.html.html-footer',
            //  Url
            'terms' => 'setting.social.terms-url',
            'privacy' => 'setting.social.privacy-url',
            'cookies' => 'setting.social.cookies-url',
            'cookieText' => 'setting.social.cookies-text',
            //  images
            'image' => 'setting.image.logo',
            'logo' => 'setting.image.logo',
            'logo_footer' => 'setting.image.logo_footer',
            'favicon' => 'setting.image.favicon',
            'logo_dark' => 'setting.image.logo_dark',
            'url' => '',
            'base_url' => '',
        ];
        //  Key is Images
        $isImage = [
            'logo', 'logo_footer', 'favicon', 'logo_dark', 'image',
        ];

        $data = [];

        foreach ($keySupported as $key => $value) {
            if (in_array($key, $isImage, true)) {
                $url = url(iSeo($key . '.url'));
                $data[$key] = $url;
            } else {
                $data[$key] = iSeo($key);
            }
        }

        $captchaProvider = $this->captcha->provider();
        $data['captcha_provider'] = $captchaProvider;
        $data['turnstile_site_key'] = $captchaProvider === 'cloudflare'
            ? (string) iSetting('api.CLOUDFLARE_TURNSTILE_SITE_KEY', '')
            : '';

        $this->cachePublicPayload(CacheConstants::CACHE_SETTINGS, $data, $this->sharedPayloadTtl());

        return ires()->data($data);
    }

    /**
     * Expiry for the Redis keys the website reads instead of calling this API.
     *
     * `cache:static:main-menu`, `:footer-menu` and `:settings` are a namespace
     * shared with the frontend, which reads them directly and skips the HTTP
     * call on a hit. They were written with no expiry, so any invalidation that
     * failed to run left the site serving a menu that no longer matched the
     * database — for as long as the key survived. A ceiling bounds that to one
     * window instead of forever.
     *
     * @return int
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-03)
     */
    protected function sharedPayloadTtl(): int
    {
        return max(0, (int) config('core.public_menu.shared_payload_ttl', 3600));
    }

    protected function cachePublicPayload(string $cacheKey, mixed $payload, ?int $ttl = null, array $debugContext = []): void
    {
        try {
            RedisHelper::getInstance()->set($cacheKey, $payload, $ttl);
        } catch (Throwable $exception) {
            logger()->warning('Skip public V3 static cache write to avoid 500 from cache layer', [
                'cache_key' => $cacheKey,
                'context' => $debugContext,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get router list for this api
     *
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 15:24 ICT
     */
    public function routes(): mixed
    {
        $routes = [
            route('v3.static.routes') => [
                'method' => 'GET',
                'description' => 'Get router list for this api',
                'last_update' => '2024-04-20, 15:24 ICT',
            ],
            route('v3.auth.sign-in') => [
                'method' => 'POST',
                'description' => 'Sign in',
                'last_update' => '2024-04-20, 14:37 ICT',
            ],
            route('v3.me.refresh') => [
                'method' => 'POST',
                'description' => 'Refresh token',
                'last_update' => '2024-04-20, 14:37 ICT',
            ],
            //  Category
            route('v3.category.index') => [
                'method' => 'GET',
                'description' => 'Get category list',
                'last_update' => '2024-04-20, 15:37 ICT',
            ],
            //  Category detail
            route('v3.category.show', ['uid' => 'category-uid']) => [
                'method' => 'GET',
                'description' => 'Show detail of category by UID',
                'last_update' => '2024-04-20, 16:42 ICT',
            ],
            //  Post list by category UID
            route('v3.category.post', ['uid' => 'category-uid']) => [
                'method' => 'GET',
                'description' => 'Get post list by category UID',
                'last_update' => '2024-04-21, 09:16 ICT',
            ],
            //  Post detail
            route('v3.post.show', ['uuid' => 'post-uuid']) => [
                'method' => 'GET',
                'description' => 'Show detail of post by UUID',
                'last_update' => '2024-04-20, 16:42 ICT',
            ],
            //  Static Main Menu
            route('v3.static.main-menu') => [
                'method' => 'GET',
                'description' => 'Get main menu',
                'last_update' => '2024-04-21, 23:25 ICT',
            ],
            //  Static footer menu
            route('v3.static.footer-menu') => [
                'method' => 'GET',
                'description' => 'Get footer menu',
                'last_update' => '2024-04-21, 23:25 ICT',
            ],
            //  Static Settings
            route('v3.static.settings') => [
                'method' => 'GET',
                'description' => 'Get settings',
                'last_update' => '2024-04-21, 17:29 ICT',
            ],
            //  Static Constants
            route('v3.static.constants') => [
                'method' => 'GET',
                'description' => 'Get constants list',
                'last_update' => '2024-04-23, 20:52 ICT',
            ],
        ];

        return ires()->data($routes);
    }

    /**
     * Get constants list
     *
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-23, 20:52 ICT
     */
    public function constants(): mixed
    {
        //  Get all files in Constants folder and return all constants with value
        //
        //  Each class is loaded defensively. App\Constants\Tables references
        //  Cms\Ecommerce\Constants\EcommerceTableConstant, so on an install
        //  without that module this endpoint returned a 500 for every caller
        //  instead of the constants it could resolve. A class that cannot be
        //  loaded is skipped and logged, not fatal.
        $files = scandir(app_path('Constants'));
        $constants = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || ! str_ends_with($file, '.php')) {
                continue;
            }

            $file = str_replace('.php', '', $file);
            $class = 'App\\Constants\\' . $file;

            try {
                if (! class_exists($class) || ! method_exists($class, 'getConstants')) {
                    continue;
                }

                $constants[$file] = (new $class())::getConstants();
            } catch (Throwable $exception) {
                logger()->warning('Skip constants class that cannot be resolved', [
                    'class' => $class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ires()->data($constants);
    }
}
