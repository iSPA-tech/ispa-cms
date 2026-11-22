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

/**
 * What the account must confirm with, and whether the confirmation is locked.
 * @returns {Promise<object>} Body with `password_required`, `second_factor`, `locked`, `retry_after`, `possible`.
 */
export const fetchStepUpStatus = async () => unwrap(await ajax.get('security/step-up/status', quiet))

/**
 * Check the password; the answer is the grant or the second-factor step.
 * @param {string|null} password Password as typed (null for an account without one).
 * @returns {Promise<object>} Body.
 */
export const startStepUp = async (password) => unwrap(await ajax.post('security/step-up', { password }, quiet))

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
 * @returns {Promise<object>} Body: the second-factor step.
 */
export const stepUpEmailCode = async (challengeId = null) =>
    unwrap(await ajax.post('security/step-up/email-code', { challenge_id: challengeId }, quiet))

// ---------------------------------------------------------------------------------------------------------------------
// Grant held in memory (never in storage)
// ---------------------------------------------------------------------------------------------------------------------

let heldGrant = null
let heldUntil = 0

/**
 * Keep the grant of a successful confirmation in memory until it expires, so single-token revokes need no new confirmation.
 * @param {string} grant Grant value.
 * @param {number} expiresInSeconds Lifetime reported by the API.
 */
export const holdGrant = (grant, expiresInSeconds) => {
    heldGrant = grant || null
    // Two seconds of margin so a request never carries a grant that expires on the way.
    heldUntil = Date.now() + Math.max(0, Number(expiresInSeconds || 0) - 2) * 1000
}

/**
 * The held grant while it is still valid, else null.
 * @returns {string|null}
 */
export const heldGrantValue = () => (heldGrant && Date.now() < heldUntil ? heldGrant : null)

/** Forget the held grant (a used-up grant, a failed request that said "required", leaving the page). */
export const dropGrant = () => {
    heldGrant = null
    heldUntil = 0
}

/**
 * Runner of actions that need a step-up grant. `run(action, { consume })` calls the action with the held grant, or opens the
 * confirmation dialog first and calls it after `confirmed`. A bulk action (`consume: true`) uses the grant up. A server answer
 * `step_up_required` forgets the held grant and asks again once. Bind `open` to the dialog and call `confirmed` / `cancelled`
 * from its events.
 * @returns {{ open: import('vue').Ref<boolean>, run: Function, confirmed: Function, cancelled: Function }}
 */
export const useStepUpRunner = () => {
    const open = ref(false)
    let pending = null

    const call = async (action, consume, grant) => {
        if (consume) dropGrant()
        try {
            return await action(grant)
        } catch (err) {
            if (errorCode(err) === 'step_up_required') {
                dropGrant()
                pending = { action, consume }
                open.value = true
                return undefined
            }
            throw err
        }
    }

    const run = async (action, { consume = false } = {}) => {
        const grant = heldGrantValue()
        if (grant) return await call(action, consume, grant)
        pending = { action, consume }
        open.value = true
        return undefined
    }

    const confirmed = async (payload) => {
        holdGrant(payload?.grant, payload?.expires_in)
        open.value = false
        const next = pending
        pending = null
        if (!next) return undefined
        return await call(next.action, next.consume, payload?.grant)
    }

    const cancelled = () => {
        pending = null
        open.value = false
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
