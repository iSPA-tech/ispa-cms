/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Lazy page loaders of the Content admin module.
 *
 * Pages that moved live in ./pages. The shared category, comment and report
 * screens still load from base-admin through the `@/` alias.
 */
export const Post = () => import('./pages/post.vue').then((m) => m.default)
export const PostCreate = () => import('./pages/create.vue').then((m) => m.default)
export const PostUpdate = () => import('./pages/update.vue').then((m) => m.default)
export const PostManage = () => import('./pages/manage.vue').then((m) => m.default)
export const PostDashboard = () => import('./pages/dashboard.vue').then((m) => m.default)
export const PostView = () => import('./pages/view.vue').then((m) => m.default)

export const PostCrawl = () => import('./pages/crawl.vue').then((m) => m.default)
export const PostQaManage = () => import('./pages/qa-manage.vue').then((m) => m.default)
export const PostPage = () => import('./pages/pages.vue').then((m) => m.default)
export const PostQaQuestion = () => import('./pages/qa-question.vue').then((m) => m.default)
export const PostQaAnswer = () => import('./pages/qa-answer.vue').then((m) => m.default)

// Not moved yet.

export const Comment = () => import('./pages/comment.vue').then((m) => m.default)

// Media screens.
export const Media = () => import('./pages/media/media.vue').then((m) => m.default)
export const MediaDashboard = () => import('./pages/media/dashboard.vue').then((m) => m.default)
export const MediaManage = () => import('./pages/media/manage.vue').then((m) => m.default)
export const MediaBanner = () => import('./pages/media/banner.vue').then((m) => m.default)
export const MediaGallery = () => import('./pages/media/gallery.vue').then((m) => m.default)
export const MediaGalleryCreate = () => import('./pages/media/gallery-create.vue').then((m) => m.default)
export const MediaGalleryManage = () => import('./pages/media/gallery-manage.vue').then((m) => m.default)
export const MediaGalleryUpdate = () => import('./pages/media/gallery-update.vue').then((m) => m.default)

// Owned by other route groups; reused by the Posts group exactly as before.
export const CategoryManage = () => import('@/pages/category/manage.vue').then((m) => m.default)
export const Report = () => import('@/pages/report/report.vue').then((m) => m.default)

// Contacts screens.
export const Contact = () => import('./pages/contact/contact.vue').then((m) => m.default)
export const ContactDashboard = () => import('./pages/contact/dashboard.vue').then((m) => m.default)
export const ContactUpdate = () => import('./pages/contact/update.vue').then((m) => m.default)
export const ContactManage = () => import('./pages/contact/manage.vue').then((m) => m.default)
export const ContactView = () => import('./pages/contact/view.vue').then((m) => m.default)
export const ContactCompose = () => import('./pages/contact/compose.vue').then((m) => m.default)
export const ContactAllEmail = () => import('./pages/contact/all-email.vue').then((m) => m.default)
export const ContactSent = () => import('./pages/contact/sent.vue').then((m) => m.default)
export const ContactUnread = () => import('./pages/contact/unread.vue').then((m) => m.default)
export const ContactEmailView = () =>
    import('./pages/contact/email-view.vue').then((m) => m.default)
export const ContactField = () => import('./pages/contact/field.vue').then((m) => m.default)
export const ContactUpdateContact = () =>
    import('./pages/contact/contact-update.vue').then((m) => m.default)

// Calendar screen.
export const Calendar = () => import('./pages/calendar/calendar.vue').then((m) => m.default)
