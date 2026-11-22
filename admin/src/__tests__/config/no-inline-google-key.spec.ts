import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * The Google Maps key comes from the build-time `VITE_GG_TOKEN` value only. A key written into
 * `index.html` or `.env.example` ships with every build and every copy of the source. Failures name the file,
 * never the value.
 */
const ROOT = resolve(__dirname, '../../..')
const GOOGLE_KEY = /AIza[0-9A-Za-z_-]{30,}/

const read = (file: string): string => readFileSync(resolve(ROOT, file), 'utf8')

describe('Google Maps key', () => {
    it.each(['index.html', '.env.example'])('is not written into %s', (file) => {
        expect(GOOGLE_KEY.test(read(file)), `${file} holds a Google API key`).toBe(false)
    })

    it('is read from VITE_GG_TOKEN with no default', () => {
        const main = read('src/main.js')

        expect(main).toContain('import.meta.env.VITE_GG_TOKEN')
        expect(main).not.toContain('window?.iSPA?.ggToken')
        expect(read('.env.example')).toMatch(/^VITE_GG_TOKEN=$/m)
    })
})
