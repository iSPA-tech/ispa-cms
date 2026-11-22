import ajax from '@/ajax'

/**
 * Passkey failures carry an i18n key (`key`, also the message) instead of display text, so this module stays
 * free of the i18n instance; the screen that shows the failure translates `error.key`.
 */
export class PasskeyError extends Error {
    constructor(key) {
        super(key)
        this.name = 'PasskeyError'
        this.key = key
    }
}

const currentUserAgent = () => (
    typeof window === 'undefined' ? '' : window.navigator?.userAgent || ''
)

/**
 * This is only an account-management label, never an authentication signal.
 */
export const suggestPasskeyName = (userAgent = currentUserAgent(), t = (key) => key) => {
    const agent = String(userAgent).toLowerCase()
    const device = agent.includes('iphone')
        ? 'iPhone'
        : agent.includes('ipad')
            ? 'iPad'
            : agent.includes('android')
                ? agent.includes('mobile') ? t('text.passkey.device.android_phone') : t('text.passkey.device.android_tablet')
                : agent.includes('windows')
                    ? 'Windows'
                    : agent.includes('macintosh') || agent.includes('mac os x')
                        ? 'Mac'
                        : agent.includes('linux')
                            ? 'Linux'
                            : t('text.passkey.device.this_device')
    const browser = agent.includes('edg/') || agent.includes('edgios') || agent.includes('edga')
        ? 'Edge'
        : agent.includes('firefox') || agent.includes('fxios')
            ? 'Firefox'
            : agent.includes('chrome') || agent.includes('crios')
                ? 'Chrome'
                : agent.includes('safari')
                    ? 'Safari'
                    : ''

    return browser ? `${device} · ${browser}` : device
}

const toArrayBuffer = (value) => {
    if (value instanceof ArrayBuffer) {
        return value
    }

    if (ArrayBuffer.isView(value)) {
        return value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength)
    }

    if (typeof value !== 'string') {
        throw new PasskeyError('text.passkey.invalid_data')
    }

    const normalized = value.replace(/-/g, '+').replace(/_/g, '/')
    const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=')
    const binary = window.atob(padded)
    const bytes = new Uint8Array(binary.length)

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index)
    }

    return bytes.buffer
}

const toBase64Url = (value) => {
    const bytes = new Uint8Array(value)
    let binary = ''

    for (const byte of bytes) {
        binary += String.fromCharCode(byte)
    }

    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

const toCredentialDescriptor = (descriptor) => ({
    ...descriptor,
    id: toArrayBuffer(descriptor.id),
})

export const toPublicKeyRequestOptions = (options = {}) => ({
    ...options,
    challenge: toArrayBuffer(options.challenge),
    ...(Array.isArray(options.allowCredentials)
        ? {
              allowCredentials: options.allowCredentials.map(toCredentialDescriptor),
          }
        : {}),
})

export const toPublicKeyCreationOptions = (options = {}) => ({
    ...options,
    challenge: toArrayBuffer(options.challenge),
    user: {
        ...options.user,
        id: toArrayBuffer(options.user?.id),
    },
    ...(Array.isArray(options.excludeCredentials)
        ? {
              excludeCredentials: options.excludeCredentials.map(toCredentialDescriptor),
          }
        : {}),
})

export const serializePasskeyCredential = (credential) => {
    if (!credential?.response || !credential?.rawId) {
        throw new PasskeyError('text.passkey.invalid_response')
    }

    const response = credential.response
    const payload = {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: toBase64Url(response.clientDataJSON),
        },
    }

    if (response.attestationObject) {
        payload.response.attestationObject = toBase64Url(response.attestationObject)
    } else {
        payload.response.authenticatorData = toBase64Url(response.authenticatorData)
        payload.response.signature = toBase64Url(response.signature)
        payload.response.userHandle = response.userHandle ? toBase64Url(response.userHandle) : null
    }

    const extensionResults = credential.getClientExtensionResults?.() || {}

    if (Object.keys(extensionResults).length > 0) {
        payload.clientExtensionResults = extensionResults
    }

    return payload
}

export const isPasskeySupported = () => {
    return Boolean(
        typeof window !== 'undefined' &&
            window.isSecureContext !== false &&
            window.PublicKeyCredential &&
            window.navigator?.credentials
    )
}

const ensurePasskeySupport = () => {
    if (!isPasskeySupported()) {
        throw new PasskeyError('text.passkey.unsupported')
    }
}

const dataOf = (response) => response?.data?.data || {}

export const loginWithPasskey = async () => {
    ensurePasskeySupport()

    const optionsResponse = await ajax.post('auth/passkey/options')
    const { challenge_id: challengeId, options } = dataOf(optionsResponse)
    const credential = await window.navigator.credentials.get({
        publicKey: toPublicKeyRequestOptions(options),
    })

    return ajax.post('auth/passkey/login', {
        challenge_id: challengeId,
        credential: serializePasskeyCredential(credential),
    })
}

export const getPasskeys = async () => {
    const response = await ajax.get('auth/passkeys')

    return dataOf(response).passkeys || []
}

export const registerPasskey = async (name) => {
    ensurePasskeySupport()

    const optionsResponse = await ajax.post('auth/passkeys/options')
    const { challenge_id: challengeId, options } = dataOf(optionsResponse)
    const credential = await window.navigator.credentials.create({
        publicKey: toPublicKeyCreationOptions(options),
    })

    return ajax.post('auth/passkeys', {
        challenge_id: challengeId,
        name,
        credential: serializePasskeyCredential(credential),
    })
}

export const deletePasskey = (passkeyId) => ajax.delete(`auth/passkeys/${passkeyId}`)
