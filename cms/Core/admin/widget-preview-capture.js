/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Preview pictures of saved site designs (widget hub distribution Task 6). After a design save of a widget this site
 * owns, each design is rendered with its default content in the same script-free frame the library cards use, captured
 * with `html-to-image` and uploaded as the design's picture. Every step may fail (a picture from another site can make
 * the capture refuse, a slow page can time out): a failure only leaves that design without a new picture, it never
 * touches the save and never shows a message.
 */

import { toCanvas } from 'html-to-image'
import ajax from '@/ajax'
import { loadWidgetThumbnail, thumbnailHtml, uploadDesignPreview } from './widget-library-api'

export const CAPTURE_WIDTH = 1200
// Same 16:10 shape as the library card picture.
export const CAPTURE_HEIGHT = 750
// Fallback size when the full-size picture does not fit the upload limit.
export const SMALL_WIDTH = 800
// The server refuses uploads over 1 MB.
export const MAX_UPLOAD_BYTES = 1024 * 1024
const FRAME_LOAD_TIMEOUT_MS = 8000
// Whole capture of one design (frame load plus html-to-image fetching every picture and font).
export const CAPTURE_TIMEOUT_MS = 15000

/**
 * Render `html` in an off-screen frame (no scripts: they are stripped and the frame does not allow them; same origin
 * only so the page can read the frame to capture it) and resolve with the frame's document element.
 *
 * @param {string} html Script-free design HTML.
 * @returns {Promise<{element: HTMLElement, dispose: () => void}>}
 */
export const renderInFrame = (html) =>
    new Promise((resolve, reject) => {
        const frame = document.createElement('iframe')
        frame.setAttribute('sandbox', 'allow-same-origin')
        frame.setAttribute('aria-hidden', 'true')
        frame.setAttribute('tabindex', '-1')
        frame.setAttribute('referrerpolicy', 'no-referrer')
        frame.style.cssText = `position:fixed;left:-10000px;top:0;width:${CAPTURE_WIDTH}px;height:${CAPTURE_HEIGHT}px;border:0;background:#fff;`
        const dispose = () => frame.remove()
        const timer = setTimeout(() => (dispose(), reject(new Error('preview frame timeout'))), FRAME_LOAD_TIMEOUT_MS)
        frame.addEventListener('load', () => {
            clearTimeout(timer)
            const element = frame.contentDocument?.documentElement
            if (!element) {
                dispose()
                reject(new Error('preview frame unreadable'))
                return
            }
            resolve({ element, dispose })
        })
        frame.srcdoc = html
        document.body.appendChild(frame)
    })

/** @returns {Promise<Blob|null>} */
const canvasBlob = (canvas, type, quality) =>
    new Promise((resolve) => {
        try {
            canvas.toBlob((blob) => resolve(blob ?? null), type, quality)
        } catch {
            resolve(null)
        }
    })

/** Copy of `canvas` scaled to `width` (16:10 kept by the source). Throws when the browser cannot draw. */
const scaledCanvas = (canvas, width) => {
    const small = document.createElement('canvas')
    small.width = width
    small.height = Math.round((canvas.height * width) / canvas.width)
    const context = small.getContext('2d')
    if (!context) throw new Error('canvas unavailable')
    context.drawImage(canvas, 0, 0, small.width, small.height)
    return small
}

/**
 * Smallest acceptable encoding of a captured picture: WebP (quality 0.85) at full size; when it is over the upload
 * limit, a `SMALL_WIDTH` WebP; when the browser cannot write WebP (it hands back PNG), a `SMALL_WIDTH` PNG.
 *
 * @param {HTMLCanvasElement} canvas Full-size capture.
 * @returns {Promise<Blob>} Rejects when nothing fits {@link MAX_UPLOAD_BYTES}.
 */
export const encodeCapture = async (canvas) => {
    const fits = (blob) => blob !== null && blob.size > 0 && blob.size <= MAX_UPLOAD_BYTES
    const webp = await canvasBlob(canvas, 'image/webp', 0.85)
    const webpSupported = webp?.type === 'image/webp'
    if (webpSupported && fits(webp)) return webp

    const small = scaledCanvas(canvas, SMALL_WIDTH)
    if (webpSupported) {
        const smallWebp = await canvasBlob(small, 'image/webp', 0.75)
        if (fits(smallWebp)) return smallWebp
    }
    const png = await canvasBlob(small, 'image/png')
    if (fits(png)) return png

    throw new Error('preview too large')
}

/**
 * Picture of one rendered design, `CAPTURE_WIDTH` wide at pixel ratio 1, encoded to fit the upload limit. Gives up
 * after `timeoutMs`; the off-screen frame is removed in every case.
 *
 * @param {string} html Script-free design HTML.
 * @param {{timeoutMs?: number}} [options]
 * @returns {Promise<Blob>}
 */
export const captureDesignBlob = (html, { timeoutMs = CAPTURE_TIMEOUT_MS } = {}) => {
    let dispose = null
    let abandoned = false
    const work = (async () => {
        const frame = await renderInFrame(html)
        if (abandoned) {
            frame.dispose()
            throw new Error('preview capture timeout')
        }
        dispose = frame.dispose
        const canvas = await toCanvas(frame.element, {
            width: CAPTURE_WIDTH,
            height: CAPTURE_HEIGHT,
            pixelRatio: 1,
            backgroundColor: '#ffffff',
        })
        return encodeCapture(canvas)
    })()

    let timer
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('preview capture timeout')), timeoutMs)
    })

    return Promise.race([work, timeout]).finally(() => {
        clearTimeout(timer)
        abandoned = true
        dispose?.()
        work.catch(() => {})
    })
}

/**
 * Capture and upload a new picture for every design of a saved widget. Never rejects and never shows a message: the
 * widget is read with a silent request.
 *
 * @param {number} widgetId Widget that holds the saved designs (the site copy when the save made one).
 * @returns {Promise<{uploaded: number[], failed: number[]}>} Design ids that got a new picture / did not.
 */
export const capturePreviewsAfterSave = async (widgetId) => {
    const result = { uploaded: [], failed: [] }
    let designs = []
    // Widget version after the save, so the render is never an older cached one.
    let version = 0
    try {
        const res = (await ajax.get(`widget/${widgetId}/edit`, { silent: true }))?.data
        designs = res?.status === 'success' ? (res.data?.template ?? []) : []
        version = Number(res?.data?.version ?? 0)
    } catch {
        return result
    }

    for (const design of designs) {
        if (!(design?.id > 0)) continue
        try {
            const html = thumbnailHtml(await loadWidgetThumbnail(widgetId, design.id, version))
            if (html === '') throw new Error('blank render')
            await uploadDesignPreview(design.id, await captureDesignBlob(html))
            result.uploaded.push(design.id)
        } catch {
            result.failed.push(design.id)
        }
    }

    return result
}
