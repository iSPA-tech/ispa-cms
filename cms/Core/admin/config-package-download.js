/**
 * Authenticated download of a finished export, shared by the export and history tabs.
 */

import ajax from '@/ajax'
import { downloadUrl } from '@/libs/configPackageApi'

/** File name used when the response carries none. */
export const DEFAULT_FILENAME = 'config-package.json'

/**
 * File name from a Content-Disposition header, or the default.
 *
 * @param {string|undefined} header Header value.
 * @returns {string}
 */
export function filenameFrom(header) {
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header || '')
    return match ? decodeURIComponent(match[1]) : DEFAULT_FILENAME
}

/**
 * Download the package of an export run as an authenticated blob and hand it to the browser as a file.
 * Rejects when the request fails; callers show their own translated error.
 *
 * @param {string} runUuid Export run uuid.
 * @returns {Promise<void>}
 */
export async function downloadPackage(runUuid) {
    const res = await ajax.get(downloadUrl(runUuid), { responseType: 'blob', silent: true })
    const url = URL.createObjectURL(res.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filenameFrom(res.headers?.['content-disposition'])
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}
