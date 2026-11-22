/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Content Posts menu group. Children that point at the category and comment screens stay here, as today.
 */
export const contentNavigationConfig = {
    name: 'post-dashboard',
    group: 'post',
    text: 'nav.a.posts',
    rule: 'post.viewAny',
    icon: 'text-box-edit-outline',
    module: 'Content',
    children: [
        {
            name: 'post-post',
            text: ['nav.a.manage', 'text.type.post'],
            rule: 'post.viewAny',
            icon: 'web',
            counter: 0,
        },
        {
            name: 'post-content',
            text: ['nav.a.contents'],
            rule: 'content.viewAny',
            icon: 'text-box-multiple-outline',
        },
        {
            name: 'post-audio',
            text: ['nav.a.manage', 'text.type.audio'],
            rule: 'post.audio',
            icon: 'podcast',
            counter: 0,
        },
        {
            name: 'post-video',
            text: ['nav.a.manage', 'text.type.video'],
            rule: 'post.video',
            icon: 'video',
            counter: 0,
        },
        {
            name: 'post-document',
            text: ['nav.a.manage', 'text.type.document'],
            rule: 'post.document',
            icon: 'text-box-multiple',
            counter: 0,
        },
        {
            name: 'post-notification',
            text: ['nav.a.manage', 'text.type.notification'],
            rule: 'post.notification',
            icon: 'bell-badge',
            counter: 0,
        },
        {
            name: 'post-admission',
            text: ['nav.a.manage', 'text.type.admission'],
            rule: 'post.admission',
            icon: 'account-school-outline',
            counter: 0,
        },
        {
            name: 'post-events',
            text: ['nav.a.manage', 'text.type.events'],
            rule: 'post.events',
            icon: 'calendar-multiple',
            counter: 0,
        },
        {
            name: 'post-qa',
            text: ['text.qa.title'],
            rule: 'post.viewAny',
            icon: 'comment-question-outline',
            counter: 0,
        },
        {
            name: 'post-page',
            text: ['nav.a.pages'],
            rule: 'post.page',
            icon: 'content-save-edit',
            counter: 0,
        },
        {
            name: 'post-category',
            text: ['nav.a.cate-post'],
            rule: 'category.viewAny',
            icon: 'file-tree',
        },
        {
            name: 'post-crawl',
            text: ['nav.a.post-crawl'],
            rule: 'post.crawl',
            icon: 'robot',
        },
        {
            name: 'post-comment',
            text: ['nav.a.comments'],
            rule: 'comment.viewAny',
            icon: 'comment',
            counter: 0,
        },
    ],
}

/**
 * Media menu group (upload library, banners, galleries and gallery categories). Registered through
 * `additionalNavigationConfigs`, so it is tagged with the Content module.
 */
export const contentMediaNavigationConfig = {
    name: 'media-dashboard',
    group: 'media',
    text: 'nav.a.media',
    rule: 'upload.viewAny',
    icon: 'multimedia',
    module: 'Content',
    children: [
        {
            name: 'banner-manage',
            text: ['nav.a.banner-manage'],
            rule: 'banner.viewAny',
            icon: 'banner',
        },
        {
            name: 'media-manage',
            text: ['nav.a.media-manage'],
            rule: 'upload.viewAny',
            icon: 'multimedia',
        },
        {
            name: 'gallery-manage',
            text: ['nav.a.galleries'],
            rule: 'gallery.viewAny',
            icon: 'galleries',
            group: 'media-gallery',
            children: [
                {
                    name: 'gallery-create',
                    text: ['nav.a.gallery-create'],
                    rule: 'gallery.viewAny',
                },
            ],
        },
        {
            name: 'gallery-category',
            text: ['nav.a.gallery-category'],
            rule: 'gallery.viewAny',
            icon: 'file-tree',
        },
    ],
}

/**
 * Contacts menu group (unread, all mail, compose, contact book and forms). Registered through
 * `additionalNavigationConfigs`, so it is tagged with the Content module.
 */
export const contentContactNavigationConfig = {
    name: 'contact-dashboard',
    group: 'contact',
    text: 'nav.a.contacts',
    rule: 'contact.viewAny',
    icon: 'email-outline',
    module: 'Content',
    children: [
        {
            name: 'email-unread',
            text: ['nav.a.contact-unread'],
            rule: 'email.viewAny',
            icon: 'email',
            counter: 0,
        },
        {
            name: 'email-all',
            text: ['text.a.email-manage'],
            rule: 'email.viewAny',
            icon: 'email-multiple',
            counter: 0,
        },
        {
            name: 'email-compose',
            text: ['nav.a.email-send'],
            rule: 'email.create',
            icon: 'email-edit-outline',
        },
        // {
        //     name: 'email-sent',
        //     text: ['nav.a.email-sent'],
        //     rule: 'email.viewAny',
        //     icon: 'send-variant',
        //     counter: 0,
        // },
        {
            name: 'contact-manage',
            text: ['nav.a.contact-manage'],
            rule: 'contact.viewAny',
            icon: 'card-account-mail',
            counter: 0,
        },
        {
            name: 'contact-field',
            text: ['text.form_builder.manage_forms'],
            rule: 'field.viewAny',
            icon: 'form-textbox',
        },
    ],
}

/**
 * Calendar menu item. Registered through `additionalNavigationConfigs`, so it is tagged with the Content module.
 */
export const contentCalendarNavigationConfig = {
    name: 'calendar',
    group: 'calendar',
    text: 'nav.a.calendars',
    rule: 'calendar.viewAny',
    icon: 'calendar-multiple',
    module: 'Content',
}
