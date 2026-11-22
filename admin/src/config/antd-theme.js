/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Ant Design Vue theme for the admin, mirrored from the design tokens in `src/css/tokens.css`.
 *
 * The values are literal on purpose: Ant Design derives its hover/active ramps from `colorPrimary` at render time
 * and cannot read CSS custom properties. Keep this file and `tokens.css` in step; the token file is the source.
 *
 * Reference: docs/design-system/admin-dashboard/README.md (workspace).
 */
export const antdTheme = {
    token: {
        colorPrimary: '#006f83', // --color-brand-700: iSPA cyan, white text 5.83:1
        colorLink: '#006f83', // --color-primary-ink
        colorLinkHover: '#00596b', // --color-brand-800
        colorInfo: '#4f46e5', // --color-info (indigo-600)
        colorSuccess: '#15803d', // --color-success (green-700)
        colorWarning: '#b45309', // --color-warning (amber-700)
        colorError: '#dc2626', // --color-danger (red-600)
        // Status fills are set explicitly. Ant Design otherwise derives them from the dark status colours above and gets
        // grey-olive tints (success bg #b4bfb6, 2.64:1 for its text); these are the token-file soft fills.
        colorSuccessBg: '#dcfce7', // --color-success-soft
        colorSuccessBorder: '#bbf7d0', // green-200
        colorWarningBg: '#fef3c7', // --color-warning-soft
        colorWarningBorder: '#fde68a', // amber-200
        colorErrorBg: '#fee2e2', // --color-danger-soft
        colorErrorBorder: '#fecaca', // red-200
        colorInfoBg: '#e0e7ff', // --color-info-soft
        colorInfoBorder: '#c7d2fe', // indigo-200
        colorTextBase: '#0f172a', // --color-ink (neutral-900)
        colorBorder: '#cbd5e1', // --color-border-strong (neutral-300), matches the existing input border weight
        borderRadius: 8, // --radius-md
        fontFamily: "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif", // --font-sans
    },
}

/**
 * Token overrides applied on top of Ant Design's dark algorithm when the admin runs with `.dark` on `<html>`.
 * Mirrors the `.dark` block of `src/css/tokens.css` (neutral-800 surface, neutral-900 layout, neutral-100 ink).
 */
export const antdDarkTokens = {
    colorBgBase: '#1e293b', // --color-surface
    colorBgLayout: '#0f172a', // --color-bg
    colorBgContainer: '#1e293b', // --color-surface
    colorBgElevated: '#334155', // --color-surface-hover
    colorTextBase: '#f1f5f9', // --color-ink
    colorBorder: '#475569', // --color-border-strong
    colorBorderSecondary: '#334155', // --color-border
    colorSuccessBg: 'rgba(74, 222, 128, 0.14)', // --color-success-soft (dark)
    colorSuccessBorder: 'rgba(74, 222, 128, 0.32)',
    colorWarningBg: 'rgba(251, 191, 36, 0.14)', // --color-warning-soft (dark)
    colorWarningBorder: 'rgba(251, 191, 36, 0.32)',
    colorErrorBg: 'rgba(248, 113, 113, 0.14)', // --color-danger-soft (dark)
    colorErrorBorder: 'rgba(248, 113, 113, 0.32)',
    colorInfoBg: 'rgba(165, 180, 252, 0.16)', // --color-info-soft (dark)
    colorInfoBorder: 'rgba(165, 180, 252, 0.34)',
    colorLink: '#72d1e4', // --color-primary-ink (dark) = brand-300, 8.33:1 on the surface
    colorLinkHover: '#a3e8f7', // brand-200
}

/**
 * Component overrides for the dark theme. Tabs colour the active label and ink bar with `colorPrimary`; brand-700 is a
 * fill colour and is too dark as text on the dark surface, so tabs use the dark primary ink instead.
 */
export const antdDarkComponents = {
    Tabs: {
        colorPrimary: '#72d1e4', // brand-300
        colorPrimaryHover: '#a3e8f7', // brand-200
        colorPrimaryActive: '#3ab7cd', // brand-400
    },
}

export default antdTheme
