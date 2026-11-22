/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Users menu group. Core is always on, so the group carries no `module` tag: its routes never get `meta.module` and
 * the module activation gate never blocks them. Item rules are the permission gate of the matching routes.
 */
export const coreUserNavigationConfig = {
    name: 'user-dashboard',
    group: 'user',
    text: 'nav.a.users',
    rule: 'user.viewAny',
    icon: 'account-group',
    children: [
        {
            name: 'user-manage',
            text: ['nav.a.manage'],
            rule: 'user.viewAny',
            icon: 'format-list-bulleted',
            position: 0,
        },
        {
            name: 'user-roles',
            text: ['nav.a.roles'],
            rule: 'role.viewAny',
            icon: 'account-supervisor',
            position: 2,
        },
        {
            name: 'user-permission',
            text: ['nav.a.permission'],
            rule: 'permission.viewAny',
            icon: 'account-multiple-check',
            position: 3,
        },
    ],
}

/**
 * Languages menu group (languages, translations, provinces). Core is always on, so no `module` tag. The sidebar slot
 * comes from `NAV_ORDER` (`language-manage`).
 */
export const coreLanguageNavigationConfig = {
    name: 'language-manage',
    group: 'language',
    text: 'nav.a.languages',
    rule: 'contact.viewAny',
    icon: 'translate-variant',
    children: [
        {
            name: 'language-create',
            text: ['nav.b.add', 'nav.a.languages'],
            rule: 'language.create',
            icon: 'translate-variant',
        },
        {
            name: 'language-manage',
            text: ['nav.a.manage', 'nav.a.languages'],
            rule: 'language.viewAny',
            icon: 'translate-variant',
        },
        {
            name: 'language-translate-admin',
            text: ['nav.a.translates'],
            rule: 'translate.viewAny',
            group: 'language-translate',
            icon: 'translate-variant',
            children: [
                {
                    name: 'language-translate-web',
                    text: ['nav.a.translates', 'nav.a.trans-web'],
                    rule: '',
                    icon: 'translate-variant',
                },
                {
                    name: 'language-translate-app',
                    text: ['nav.a.translates', 'nav.a.trans-app'],
                    rule: '',
                    icon: 'translate-variant',
                },
            ],
        },
        {
            name: 'language-translate-email',
            text: ['nav.a.trans-email'],
            rule: 'email.viewAny',
            icon: 'translate-variant',
        },
        {
            name: 'province-manage',
            text: ['nav.a.provinces-manage'],
            rule: 'language.viewAny',
            icon: 'translate-variant',
        },
    ],
}

/**
 * Widget library menu entry (one item, no children). Core is always on, so no `module` tag: the entry and its screen
 * stay available with the Widget module off. The sidebar slot comes from `NAV_ORDER` (`widget-library`).
 */
export const coreWidgetLibraryNavigationConfig = {
    name: 'widget-library',
    group: 'widget-library',
    text: 'nav.a.widget-library',
    rule: 'widget.viewAny',
    icon: 'puzzle-outline',
}
