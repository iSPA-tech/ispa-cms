/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * A widget hub version as people read it. Newer versions are a year, a month and a build of that month kept as one
 * integer YYYYMMNN: 20261101 → "2026.11.01", 20261102 → "2026.11.02". Older versions are small numbers and stay as
 * they are (3 → "3"). Nothing known → "".
 */
export const formatVersion = (version) => {
    const value = Math.trunc(Number(version)) || 0
    if (value <= 0) return ''
    if (value < 20000101) return String(value)
    const digits = String(value)
    return `${digits.slice(0, 4)}.${digits.slice(4, 6)}.${digits.slice(6, 8)}`
}
