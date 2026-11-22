/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/** Comment settings (the banned word list). */
export const getCommentSettings = async () => {
    return await ajax.get('/comment-settings').then((res) => res.data || {})
}

export const saveCommentSettings = async (data = {}) => {
    return await ajax.put('/comment-settings', data).then((res) => res.data || {})
}

/** Banned words the server matched on a comment (always an array). */
export const bannedWordsOf = (comment = {}) =>
    Array.isArray(comment?.banned_words) ? comment.banned_words.filter(Boolean) : []

/** One entry per line, trimmed, blanks dropped. The server trims and removes duplicates again. */
export const linesToWords = (text = '') =>
    String(text ?? '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean)

const COMBINING_MARK = /[\u0300-\u036f]/
const COMBINING_MARKS = /[\u0300-\u036f]/g

/** Lower-case, strip Vietnamese accents (`lừa đảo` -> `lua dao`). */
const fold = (value = '') =>
    String(value).normalize('NFD').replace(COMBINING_MARKS, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase()

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/**
 * Split `text` into `{ text, hit }` segments, `hit` marking a banned word or phrase. Matching ignores case and
 * Vietnamese accents and only accepts whole words. Display help only: the server list on the comment is the truth.
 */
export const highlightSegments = (text = '', words = []) => {
    const source = String(text ?? '')
    const needles = (Array.isArray(words) ? words : [])
        .map((word) => fold(word).trim().split(/\s+/).filter(Boolean))
        .filter((parts) => parts.length)

    if (!source || !needles.length) {
        return source ? [{ text: source, hit: false }] : []
    }

    // Fold character by character and remember which source index each folded character came from.
    let folded = ''
    const origin = []
    for (let index = 0; index < source.length; index += 1) {
        const piece = fold(source[index])
        for (let offset = 0; offset < piece.length; offset += 1) {
            origin.push(index)
        }
        folded += piece
    }

    const ranges = []
    needles.forEach((parts) => {
        let pattern
        try {
            pattern = new RegExp(
                `(?<![\\p{L}\\p{N}])${parts.map(escapeRegExp).join('\\s+')}(?![\\p{L}\\p{N}])`,
                'gu'
            )
        } catch {
            return
        }
        for (const match of folded.matchAll(pattern)) {
            if (!match[0].length) continue
            const start = origin[match.index]
            let end = origin[match.index + match[0].length - 1] + 1
            // Keep the accents of a decomposed last letter inside the mark.
            while (end < source.length && COMBINING_MARK.test(source[end])) {
                end += 1
            }
            ranges.push([start, end])
        }
    })

    if (!ranges.length) {
        return [{ text: source, hit: false }]
    }

    ranges.sort((a, b) => a[0] - b[0])
    const merged = [ranges[0]]
    ranges.slice(1).forEach(([start, end]) => {
        const last = merged[merged.length - 1]
        if (start <= last[1]) {
            last[1] = Math.max(last[1], end)
        } else {
            merged.push([start, end])
        }
    })

    const segments = []
    let cursor = 0
    merged.forEach(([start, end]) => {
        if (start > cursor) {
            segments.push({ text: source.slice(cursor, start), hit: false })
        }
        segments.push({ text: source.slice(start, end), hit: true })
        cursor = end
    })
    if (cursor < source.length) {
        segments.push({ text: source.slice(cursor), hit: false })
    }

    return segments
}
