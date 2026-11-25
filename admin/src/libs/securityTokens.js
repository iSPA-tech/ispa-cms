/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { ref } from 'vue'
import ajax from '@/ajax'

/**
 * Client of the API token screens: the step-up confirmation, the token settings and
 * the token list and revoke routes. A response body is `{ data: ... }`; every function resolves to that body.
 * Errors are shown translated by the screens, so the shared ajax alert (raw server text) stays off (`silent`).
 */
const quiet = { silent: true }

/** Header that carries the step-up grant to the revoke routes. */
export const STEP_UP_HEADER = 'X-Step-Up-Grant'

/** Word the Webmaster types before a bulk revoke (the API compares it exactly). */
export const CONFIRM_WORD = 'REVOKE'

/** API families the screens know (the API answers with the same list in `audiences`). */
export const AUDIENCES = ['admin', 'account', 'client', 'service']

/** Lifetime options in the order the form lists them (same keys as the API). */
export const LIFETIME_OPTIONS = ['1h', '6h', '12h', '1d', '7d', '30d', '90d', 'never']

/** Settings values never sent as a body without the caller choosing them. */
const unwrap = (res) => res.data || {}

/**
 * Request config that carries a step-up grant. Pure.
 * @param {string} grant Grant from the step-up confirmation.
 * @returns {object} Axios config.
 */
export const grantConfig = (grant) => ({ ...quiet, headers: { [STEP_UP_HEADER]: grant } })

/**
 * Machine code of a failed request (`step_up_failed`, `step_up_locked`, `CURRENT_TOKEN_NOT_REVOCABLE` ...). Pure.
 * @param {unknown} err Rejection of a request.
 * @returns {string} Error code, empty when the response carried none.
 */
export const errorCode = (err) => String(err?.response?.data?.error_code || err?.response?.data?.errorCode || '')

/**
 * Seconds to wait after a 429 (`Retry-After` header, else the body field). Pure.
 * @param {unknown} err Rejection of a request.
 * @returns {number} Seconds, 0 when unknown.
 */
export const retryAfterSeconds = (err) => {
    const header = Number(err?.response?.headers?.['retry-after'])
    const body = Number(err?.response?.data?.retry_after)
    const value = Number.isFinite(header) && header > 0 ? header : body
    return Number.isFinite(value) && value > 0 ? Math.ceil(value) : 0
}

// ---------------------------------------------------------------------------------------------------------------------
// Step-up confirmation (any signed-in operator, own account)
// ---------------------------------------------------------------------------------------------------------------------

/** Purpose of a grant when the caller names none (ending sessions). A grant only works for the purpose it was made for. */
export const DEFAULT_PURPOSE = 'revoke_tokens'

/** The default purpose is left out of the body, as before purposes existed. */
const withPurpose = (body, purpose) => (purpose && purpose !== DEFAULT_PURPOSE ? { ...body, purpose } : body)

/**
 * What the account must confirm with, and whether the confirmation is locked.
 * @returns {Promise<object>} Body with `password_required`, `second_factor`, `locked`, `retry_after`, `possible`.
 */
export const fetchStepUpStatus = async () => unwrap(await ajax.get('security/step-up/status', quiet))

/**
 * Check the password; the answer is the grant or the second-factor step.
 * @param {string|null} password Password as typed (null for an account without one).
 * @param {string} [purpose] What the grant is for (default: ending sessions).
 * @returns {Promise<object>} Body.
 */
export const startStepUp = async (password, purpose = DEFAULT_PURPOSE) =>
    unwrap(await ajax.post('security/step-up', withPurpose({ password }, purpose), quiet))

/**
 * Check the code of the open second-factor step.
 * @param {string} challengeId Confirmation id from the previous answer.
 * @param {string} code Code as typed.
 * @param {string|null} method `recovery_code` to use a recovery code.
 * @returns {Promise<object>} Body: the grant or the next step.
 */
export const stepUpSecondFactor = async (challengeId, code, method = null) =>
    unwrap(await ajax.post('security/step-up/second-factor', { challenge_id: challengeId, code, method }, quiet))

/**
 * Send (or send again) the e-mail code. Without a confirmation id it starts the confirmation of an account that has no password.
 * @param {string|null} challengeId Open confirmation, or null.
 * @param {string} [purpose] What the grant is for; only read when this call starts the confirmation.
 * @returns {Promise<object>} Body: the second-factor step.
 */
export const stepUpEmailCode = async (challengeId = null, purpose = DEFAULT_PURPOSE) =>
    unwrap(await ajax.post('security/step-up/email-code', withPurpose({ challenge_id: challengeId }, purpose), quiet))

// ---------------------------------------------------------------------------------------------------------------------
// Grant held in memory (never in storage)
// ---------------------------------------------------------------------------------------------------------------------

/** purpose => { grant, until } */
const held = {}

/**
 * Keep the grant of a successful confirmation in memory until it expires, so single-token revokes need no new confirmation.
 * @param {string} grant Grant value.
 * @param {number} expiresInSeconds Lifetime reported by the API.
 * @param {string} [purpose] Purpose the grant was made for.
 */
export const holdGrant = (grant, expiresInSeconds, purpose = DEFAULT_PURPOSE) => {
    if (!grant) {
        delete held[purpose]
        return
    }
    // Two seconds of margin so a request never carries a grant that expires on the way.
    held[purpose] = { grant, until: Date.now() + Math.max(0, Number(expiresInSeconds || 0) - 2) * 1000 }
}

/**
 * The held grant while it is still valid, else null.
 * @param {string} [purpose] Purpose the grant is needed for.
 * @returns {string|null}
 */
export const heldGrantValue = (purpose = DEFAULT_PURPOSE) => {
    const row = held[purpose]
    return row && Date.now() < row.until ? row.grant : null
}

/**
 * Forget the held grant (a used-up grant, a failed request that said "required", leaving the page).
 * @param {string} [purpose] Purpose to forget.
 */
export const dropGrant = (purpose = DEFAULT_PURPOSE) => {
    delete held[purpose]
}

/**
 * Forget every held grant (sign-out, signing in as another user): a grant never outlives the account it was made for
 * in this tab.
 */
export const dropAllGrants = () => {
    for (const purpose of Object.keys(held)) delete held[purpose]
}

/**
 * Runner of actions that need a step-up grant. `run(action, { consume })` calls the action with the held grant, or opens the
 * confirmation dialog first and calls it after `confirmed`. A bulk action (`consume: true`) uses the grant up. A server answer
 * `step_up_required` forgets the held grant and asks again once. Bind `open` to the dialog and call `confirmed` / `cancelled`
 * from its events.
 *
 * With `lazy: true` the action is tried first without a grant and the dialog opens only when the server asks for one
 * (a publish needs a confirmation only when it changes sensitive behaviour); `run(action, { askFirst: true })` skips that
 * first try when the caller already knows the answer. With `wait: true` (implied by `lazy`) `run`
 * resolves when the whole flow has ended: with the action's result, or with `undefined` when the operator closed the dialog.
 *
 * @param {{ purpose?: string, lazy?: boolean, wait?: boolean }} [options]
 * @returns {{ open: import('vue').Ref<boolean>, run: Function, confirmed: Function, cancelled: Function }}
 */
export const useStepUpRunner = ({ purpose = DEFAULT_PURPOSE, lazy = false, wait = lazy } = {}) => {
    const open = ref(false)
    let pending = null

    /** Open the dialog; waiting runners hand back a promise that settles when the confirmed action has run. */
    const ask = (action, consume) => {
        open.value = true
        if (!wait) {
            pending = { action, consume }
            return undefined
        }
        return new Promise((resolve, reject) => {
            pending = { action, consume, resolve, reject }
        })
    }

    const call = async (action, consume, grant, mayAsk = true) => {
        if (consume) dropGrant(purpose)
        try {
            return await action(grant)
        } catch (err) {
            if (errorCode(err) === 'step_up_required' && mayAsk) {
                dropGrant(purpose)
                return ask(action, consume)
            }
            throw err
        }
    }

    const run = async (action, { consume = false, askFirst = false } = {}) => {
        const grant = heldGrantValue(purpose)
        // `askFirst`: a lazy runner whose caller already knows a confirmation is needed skips the try without a grant.
        if (grant || (lazy && !askFirst)) return await call(action, consume, grant)
        return ask(action, consume)
    }

    const confirmed = async (payload) => {
        holdGrant(payload?.grant, payload?.expires_in, purpose)
        open.value = false
        const next = pending
        pending = null
        if (!next) return undefined
        if (!next.resolve) return await call(next.action, next.consume, payload?.grant)
        // One retry only: a second "required" answer goes to the caller as the error it is.
        try {
            const result = await call(next.action, next.consume, payload?.grant, false)
            next.resolve(result)
            return result
        } catch (err) {
            next.reject(err)
            return undefined
        }
    }

    const cancelled = () => {
        const next = pending
        pending = null
        open.value = false
        next?.resolve?.(undefined)
    }

    return { open, run, confirmed, cancelled }
}

// ---------------------------------------------------------------------------------------------------------------------
// Token settings (Webmaster)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Current settings with the option lists, the recommended values and the "never expires" warnings.
 * @returns {Promise<object>} Body.
 */
export const fetchTokenSettings = async () => unwrap(await ajax.get('security/token-settings', quiet))

/**
 * Save a partial change; "never expires" needs `confirm_never_expire: true`.
 * @param {object} body Changed fields.
 * @returns {Promise<object>} Body.
 */
export const saveTokenSettings = async (body) => unwrap(await ajax.put('security/token-settings', body, quiet))

/**
 * Counts per audience for the saved settings (or for a candidate change); changes nothing.
 * @param {object} body Candidate change, may be empty.
 * @returns {Promise<object>} Body with `audiences`, `without_audience`, `warnings`.
 */
export const previewTokenSettings = async (body = {}) => unwrap(await ajax.post('security/token-settings/preview', body, quiet))

/**
 * Set the expiry of existing live tokens of one audience (needs a step-up grant it uses up).
 * @param {{ audience: string, revoke_past?: boolean }} body Audience and whether tokens already past the limit are ended.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body with `set`, `already_past`, `revoked`.
 */
export const applyToExisting = async (body, grant) =>
    unwrap(await ajax.post('security/token-settings/apply-to-existing', body, grantConfig(grant)))

// ---------------------------------------------------------------------------------------------------------------------
// Token lists and revoke routes
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Live tokens of the caller's own account (the token in use is marked `current`).
 * @returns {Promise<object>} Body whose `data` is the list.
 */
export const fetchMyTokens = async () => unwrap(await ajax.get('security/tokens/mine', quiet))

/**
 * Webmaster: live tokens of every account, filtered and paged by the server.
 * @param {object} params `user`, `audience`, `last_used_before`, `never_expiring`, `legacy`, `per_page`, `page`.
 * @returns {Promise<object>} Body whose `data` has `items` and `meta`.
 */
export const fetchAllTokens = async (params = {}) => unwrap(await ajax.get('security/tokens', { ...quiet, params }))

/**
 * End one other token of the caller's own account.
 * @param {string} uuid Token uuid.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body.
 */
export const revokeMyToken = async (uuid, grant) => unwrap(await ajax.delete(`security/tokens/mine/${uuid}`, grantConfig(grant)))

/**
 * End every other token of the caller's own account (uses the grant up).
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body with `revoked`.
 */
export const revokeOthers = async (grant) => unwrap(await ajax.post('security/tokens/mine/revoke-others', {}, grantConfig(grant)))

/**
 * Webmaster: end one token of any account other than the one in use.
 * @param {string} uuid Token uuid.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body.
 */
export const revokeToken = async (uuid, grant) => unwrap(await ajax.delete(`security/tokens/${uuid}`, grantConfig(grant)))

/**
 * Webmaster: end every token of one account (uses the grant up; needs the typed word).
 * @param {string} userUuid Account uuid.
 * @param {string} confirm The typed word.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body with `revoked`.
 */
export const revokeUser = async (userUuid, confirm, grant) =>
    unwrap(await ajax.post('security/tokens/revoke-user', { user: userUuid, confirm }, grantConfig(grant)))

/**
 * Webmaster: end every token of one API family (uses the grant up; needs the typed word).
 * @param {string} audience One of AUDIENCES.
 * @param {string} confirm The typed word.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body with `revoked`.
 */
export const revokeAudience = async (audience, confirm, grant) =>
    unwrap(await ajax.post('security/tokens/revoke-audience', { audience, confirm }, grantConfig(grant)))

/**
 * Webmaster: end every token of the site except the one in use (uses the grant up; needs the typed word).
 * @param {string} confirm The typed word.
 * @param {string} grant Step-up grant.
 * @returns {Promise<object>} Body with `revoked`.
 */
export const revokeAll = async (confirm, grant) =>
    unwrap(await ajax.post('security/tokens/revoke-all', { confirm }, grantConfig(grant)))
