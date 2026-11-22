import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const blade = readFileSync(new URL('../../resources/views/components/form-generate-reviewed.blade.php', import.meta.url), 'utf8')
const start = blade.indexOf('function reviewedForm{{ $fields->id }}()')
const end = blade.indexOf('</script>', start)
assert.ok(start >= 0 && end > start)
const script = blade.slice(start, end)
    .replaceAll('{{ $fields->id }}', '1')
    .replaceAll('@js($form)', '"admission"')
    .replaceAll('@js($advancedItems)', '[]')
    .replaceAll('@js($actionUrl)', '"/submit"')
    .replaceAll('@js(csrf_token())', '"csrf"')
    .replaceAll('@js($initialValues)', '{}')
    .replaceAll('@js($existingFileNames)', '{}')
    .replaceAll('@js($previewOnly)', 'false')

function formFor(verificationProvider) {
    let callback
    let posts = 0
    let execution
    const turnstile = {
        render(_element, options) {
            callback = options.callback
            execution = options.execution
            callback('early-token') // Simulate an automatic CAPTCHA completion on page load.
            return 42
        },
        execute() { callback('confirmed-token') },
        reset() {},
        getResponse() { return 'already-solved-token' },
    }
    const context = {
        window: { __captcha: { provider: 'cloudflare', verificationProvider, siteKey: 'test' }, turnstile },
        turnstile,
        document: { addEventListener() {} },
        URL,
    }
    const factory = vm.runInNewContext(`${script}; reviewedForm1`, context)
    const form = factory()
    form.$refs = { captchaToken: { value: '' } }
    form.$watch = () => {}
    form.$nextTick = (callback) => callback()
    form.post = () => { posts++ }
    form.init()
    return { form, posts: () => posts, execution: () => execution }
}

test('Turnstile cannot persist before review and explicit confirmation', () => {
    const { form, posts, execution } = formFor('cloudflare')
    assert.equal(posts(), 0)
    assert.equal(execution(), 'execute')
    form.stage = 'review'
    form.confirm()
    assert.equal(posts(), 1)
})

test('hCaptcha adapter ignores early callback and posts after confirmation', () => {
    const { form, posts } = formFor('hcaptcha')
    assert.equal(posts(), 0)
    form.stage = 'review'
    form.confirm()
    assert.equal(posts(), 1)
})
