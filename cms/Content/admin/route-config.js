/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { resourcePage } from '@/components/resource/load-resource-page'
import {
    Calendar,
    CategoryManage,
    Comment,
    Contact,
    ContactAllEmail,
    ContactCompose,
    ContactDashboard,
    ContactEmailView,
    ContactField,
    ContactManage,
    ContactSent,
    ContactUnread,
    ContactUpdateContact,
    ContactView,
    Media,
    MediaBanner,
    MediaDashboard,
    MediaGallery,
    MediaGalleryCreate,
    MediaGalleryManage,
    MediaGalleryUpdate,
    MediaManage,
    Post,
    PostCrawl,
    PostCreate,
    PostDashboard,
    PostManage,
    PostPage,
    PostQaAnswer,
    PostQaManage,
    PostQaQuestion,
    PostUpdate,
    PostView,
    Report,
} from './page-loaders'

/**
 * Content route group (path /post): the Posts screens. Children whose page has not moved yet still load from base-admin.
 */
export const contentRouteConfig = {
    text: 'nav.a.posts',
    path: 'post',
    redirect: { name: 'post-dashboard' },
    load: Post,
    group: 'post',
    name: 'post',
    children: [
        {
            text: 'text.type.post',
            path: 'dashboard',
            name: 'post-dashboard',
            load: PostDashboard,
        },
        {
            text: 'text.type.post',
            path: 'manage',
            name: 'post-post',
            load: PostManage,
        },
        {
            // Content records (the multilingual texts of places and items), served by the schema-driven resource renderer.
            text: 'nav.a.contents',
            path: 'content',
            name: 'post-content',
            load: resourcePage({ resource: 'content/contents', title: 'nav.a.contents' }),
        },
        {
            text: 'text.type.audio',
            path: 'audio',
            name: 'post-audio',
            load: PostManage,
        },
        {
            text: 'text.type.video',
            path: 'video',
            name: 'post-video',
            load: PostManage,
        },
        {
            text: 'text.type.document',
            path: 'document',
            name: 'post-document',
            load: PostManage,
        },
        {
            text: 'text.type.notification',
            path: 'notification',
            name: 'post-notification',
            load: PostManage,
        },
        {
            text: 'text.type.admission',
            path: 'admission',
            name: 'post-admission',
            load: PostManage,
        },
        {
            text: 'text.type.events',
            path: 'events',
            name: 'post-events',
            load: PostManage,
        },
        {
            text: 'text.qa.title',
            path: 'qa',
            name: 'post-qa',
            load: PostQaManage,
        },
        {
            text: 'text.qa.title',
            path: 'qa/question/create',
            name: 'post-qa-question-create',
            load: PostQaQuestion,
        },
        {
            text: 'text.qa.title',
            path: 'qa/question/update/:uuid',
            name: 'post-qa-question-update',
            load: PostQaQuestion,
        },
        {
            text: 'text.qa.title',
            path: 'qa/answer/:uuid',
            name: 'post-qa-answer',
            load: PostQaAnswer,
        },
        {
            text: 'nav.b.create',
            path: 'create',
            name: 'post-create',
            load: PostCreate,
        },
        {
            text: 'nav.b.update',
            path: 'update/:uuid',
            name: 'post-update',
            load: PostUpdate,
        },
        {
            text: 'nav.b.view-detail',
            path: 'view/:uuid',
            name: 'post-view',
            load: PostView,
        },
        {
            text: 'nav.a.cate-post',
            path: 'category',
            name: 'post-category',
            load: CategoryManage,
        },
        {
            text: 'nav.a.post-crawl',
            path: 'crawl',
            name: 'post-crawl',
            load: PostCrawl,
        },
        {
            text: 'nav.a.report',
            path: 'report',
            name: 'post-report',
            load: Report,
        },
        {
            text: 'nav.a.pages',
            path: 'page',
            name: 'post-page',
            load: PostPage,
        },
        {
            text: 'nav.a.comments',
            path: 'comment',
            name: 'post-comment',
            load: Comment,
        },
    ],
}

/**
 * Comments moderation route group (`/comments`). Registered through `additionalRouteConfigs`, so it is tagged
 * `meta.module: 'Content'`. There is no sidebar entry for it.
 */
export const contentCommentRouteConfig = {
    text: 'nav.a.comments',
    path: 'comments',
    name: 'comment-manage',
    load: Comment,
    group: 'comment',
}

/**
 * Media route group (`/media`): upload library, dashboard, banners and galleries. Registered through
 * `additionalRouteConfigs`, so it is tagged `meta.module: 'Content'`. The gallery category child reuses the shared
 * category screen. Its root stays rule-less, as before the move.
 */
export const contentMediaRouteConfig = {
    text: 'nav.a.media',
    path: 'media',
    name: 'media',
    redirect: { name: 'media-manage' },
    load: Media,
    group: 'media',
    children: [
        {
            text: 'nav.a.media-manage',
            path: 'manage',
            name: 'media-manage',
            load: MediaManage,
        },
        {
            text: 'nav.dashboard',
            path: 'dashboard',
            name: 'media-dashboard',
            load: MediaDashboard,
        },
        {
            text: 'nav.a.banner-manage',
            path: 'banner',
            name: 'banner-manage',
            load: MediaBanner,
        },
        {
            text: 'nav.a.gallery-manage',
            path: 'gallery',
            redirect: { name: 'gallery-manage' },
            load: MediaGallery,
            name: 'media-gallery',
            subGroup: 'media-gallery',
            children: [
                {
                    text: 'nav.a.gallery-manage',
                    path: 'manage',
                    name: 'gallery-manage',
                    load: MediaGalleryManage,
                },
                {
                    text: 'nav.a.gallery-create',
                    path: 'create',
                    name: 'gallery-create',
                    load: MediaGalleryCreate,
                },
                {
                    text: 'nav.b.update',
                    path: 'update/:id',
                    name: 'gallery-update',
                    load: MediaGalleryUpdate,
                },
                {
                    text: 'nav.a.gallery-category',
                    path: 'category',
                    name: 'gallery-category',
                    load: CategoryManage,
                },
            ],
        },
    ],
}

/**
 * Contacts route group (`/contact`): contact book, contact form builder and the e-mail screens. Registered through
 * `additionalRouteConfigs`, so it is tagged `meta.module: 'Content'`. Route names, paths and rules are unchanged
 * because the Webmail handover redirects are keyed by route name.
 */
export const contentContactRouteConfig = {
    text: 'nav.a.contacts',
    path: 'contact',
    name: 'contact',
    load: Contact,
    group: 'contact',
    children: [
        {
            text: 'nav.a.contacts',
            // Own path: it used to share `manage` with `contact-manage`, which made the contact list unreachable.
            path: 'dashboard',
            name: 'contact-dashboard',
            load: ContactDashboard,
        },
        {
            text: 'nav.a.contacts',
            path: 'manage',
            name: 'contact-manage',
            load: ContactManage,
        },
        {
            text: 'nav.b.update',
            path: 'edit/:id',
            name: 'contact-update',
            load: ContactUpdateContact,
        },
        {
            text: 'nav.a.email-send',
            path: 'compose',
            name: 'email-compose',
            load: ContactCompose,
        },
        {
            text: 'nav.b.view-detail',
            path: 'view/:id',
            name: 'contact-view',
            load: ContactView,
        },
        {
            text: 'text.form_builder.forms',
            path: 'field',
            name: 'contact-field',
            load: ContactField,
        },
        {
            text: 'text.a.email-manage',
            path: 'all-email',
            name: 'email-all',
            load: ContactAllEmail,
        },
        {
            text: 'nav.a.email-sent',
            path: 'sent',
            name: 'email-sent',
            load: ContactSent,
        },
        {
            text: 'nav.a.contact-unread',
            path: 'unread',
            name: 'email-unread',
            load: ContactUnread,
        },
        {
            text: 'nav.b.view-detail',
            path: 'mail/:uuid',
            name: 'email-view',
            load: ContactEmailView,
        },
    ],
}

/**
 * Calendar route group (`/calendar`): the calendar page. Registered through `additionalRouteConfigs`, so it is
 * tagged `meta.module: 'Content'`. Route name, path and group are unchanged.
 */
export const contentCalendarRouteConfig = {
    text: 'nav.a.calendars',
    path: 'calendar',
    name: 'calendar',
    load: Calendar,
    group: 'calendar',
}
