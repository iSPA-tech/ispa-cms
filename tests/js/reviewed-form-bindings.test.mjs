import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const blade = readFileSync(new URL('../../resources/views/components/form-generate-reviewed.blade.php', import.meta.url), 'utf8')

// Alpine 3 coerces an undefined x-bind result to "" whenever the expression contains a dot, and "" sets a
// boolean attribute. `:disabled="sourceStates[name]?.loading"` therefore disabled every select that has no
// dynamic source (regression from the scoped option sources). Boolean attributes must bind a real boolean.
test('boolean attribute bindings coerce optional-chain lookups to booleans', () => {
    const bindings = [...blade.matchAll(/:(disabled|required|readonly|checked|selected)="([^"]*)"/g)]
    assert.ok(bindings.length >= 4)
    for (const [, attribute, expression] of bindings) {
        if (!expression.includes('?.')) continue
        assert.ok(/^(!!|!)|===|!==/.test(expression), `:${attribute}="${expression}" must evaluate to a boolean, not an optional chain`)
    }
})
