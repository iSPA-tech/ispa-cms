/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import './bootstrap'

//  Image lazy loading
document.addEventListener('DOMContentLoaded', () => {
    const windowWidth = window.innerWidth

    const getContainerWidth = (element) => {
        if (!element) {
            return windowWidth
        }

        const container =
            element.closest('[data-lazy-container]') ?? element.parentElement

        const rawContainerWidth =
            container?.getBoundingClientRect()?.width ??
            container?.clientWidth ??
            container?.offsetWidth ??
            0
        const rawElementWidth =
            element.getBoundingClientRect()?.width ??
            element.clientWidth ??
            element.offsetWidth ??
            0

        const candidateWidth = rawContainerWidth || rawElementWidth

        if (candidateWidth > 0) {
            return Math.min(candidateWidth, windowWidth)
        }

        return windowWidth
    }

    const pickSourceByWidth = (data = {}, width = windowWidth) => {
        if (!data || Object.keys(data).length < 1) {
            return null
        }

        const variantKeys = ['sm', 'md', 'lg', 'xl']
        const sizedVariants = variantKeys
            .map((key, index) => ({
                key,
                index,
                source: data[key],
                width: Number.parseInt(data[`${key}Width`] ?? '0', 10),
            }))
            .filter((variant) => variant.source && variant.width > 0)

        if (sizedVariants.length > 0) {
            const minSizeIndex = variantKeys.indexOf(data.minSize)
            const allowedVariants =
                minSizeIndex >= 0
                    ? sizedVariants.filter(
                          (variant) => variant.index >= minSizeIndex
                      )
                    : sizedVariants
            const candidates =
                allowedVariants.length > 0 ? allowedVariants : sizedVariants
            const fittingVariants = candidates.filter(
                (variant) => variant.width <= width
            )

            return (
                fittingVariants[fittingVariants.length - 1] ?? candidates[0]
            ).source
        }

        if (width > 200 && width <= 768) {
            return data.md || data.sm
        }
        if (width > 768 && width <= 1024) {
            return data.lg || data.md || data.sm
        }
        if (width > 1024 && width <= 1440) {
            return data.xl || data.lg || data.md || data.sm
        }
        if (width > 1440) {
            return data.url || data.xl || data.lg || data.md || data.sm
        }

        return data.sm || data.md || data.lg || data.url
    }

    //  Custom lazy load for images
    const allCustomLazyImages = [].slice.call(
        document.querySelectorAll('img[data-lazy]')
    )

    // Lazy load images
    const lazyImages = [].slice.call(
        document.querySelectorAll('img[loading=lazy]')
    )
    if ('IntersectionObserver' in window) {
        let customLazyImageObserver = new IntersectionObserver(function (
            entries,
            observer
        ) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    let lazyImage = entry.target
                    let data = lazyImage.dataset
                    const minSize = data.minSize
                    if (Object.keys(data).length > 1) {
                        //  Get current image width
                        const imgWidth = getContainerWidth(lazyImage)
                        let imgSrc = pickSourceByWidth(data, imgWidth)

                        // Define size order for comparison
                        const sizeOrder = ['sm', 'md', 'lg', 'xl', 'url']

                        // If image has data-min-size, use it if imgSrc is less than minSize
                        if (minSize) {
                            const currentSizeIndex = sizeOrder.indexOf(
                                Object.keys(data).find(
                                    (key) => data[key] === imgSrc
                                )
                            )
                            const minSizeIndex = sizeOrder.indexOf(minSize)

                            if (currentSizeIndex < minSizeIndex) {
                                imgSrc = data[minSize]
                            }
                        }

                        lazyImage.src = imgSrc
                        lazyImage.removeAttribute('loading')
                        customLazyImageObserver.unobserve(lazyImage)
                    }
                }
            })
        })

        allCustomLazyImages.forEach(function (lazyImage) {
            customLazyImageObserver.observe(lazyImage)
        })

        let lazyImageObserver = new IntersectionObserver(function (
            entries,
            observer
        ) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    let lazyImage = entry.target
                    let data = lazyImage.dataset
                    if (Object.keys(data).length > 0 && data.src) {
                        lazyImage.src = lazyImage.dataset.src
                        if (lazyImage.dataset.srcset) {
                            lazyImage.srcset = lazyImage.dataset.srcset
                        }
                        lazyImage.removeAttribute('loading')
                        lazyImageObserver.unobserve(lazyImage)
                    }
                }
            })
        })

        lazyImages.forEach(function (lazyImage) {
            lazyImageObserver.observe(lazyImage)
        })
    }

    // Make href inside clickable to scroll to the anchor
    const allAnchors = document.querySelectorAll('#content .prose a')
    if (allAnchors.length > 0) {
        allAnchors.forEach((anchor) => {
            // add target="_blank" to all external links
            if (
                anchor.getAttribute('href').startsWith('http') &&
                !anchor.getAttribute('href').includes(location.hostname)
            ) {
                anchor.setAttribute('target', '_blank')
                anchor.setAttribute('rel', 'noopener noreferrer')
            }
            // add id to footnote links
            else if (anchor.getAttribute('href').startsWith('#')) {
                //  Get id from name attribute
                const id = anchor.getAttribute('name')
                if (id) {
                    anchor.setAttribute('id', id)
                }
            }
        })
    }
})
