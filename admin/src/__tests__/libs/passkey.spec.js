import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/ajax', () => ({
    default: {
        delete: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
    },
}))

import ajax from '@/ajax'
import {
    loginWithPasskey,
    registerPasskey,
    serializePasskeyCredential,
    suggestPasskeyName,
    toPublicKeyCreationOptions,
    toPublicKeyRequestOptions,
} from '@/libs/passkey'

const buffer = (...bytes) => new Uint8Array(bytes).buffer

const assertionCredential = () => ({
    id: 'credential-id',
    rawId: buffer(1, 2, 3),
    type: 'public-key',
    response: {
        authenticatorData: buffer(4, 5, 6),
        clientDataJSON: buffer(7, 8, 9),
        signature: buffer(10, 11, 12),
        userHandle: null,
    },
    getClientExtensionResults: () => ({}),
})

describe('passkey browser adapter', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        Object.defineProperty(window, 'PublicKeyCredential', {
            configurable: true,
            value: class PublicKeyCredential {},
        })
        Object.defineProperty(window, 'isSecureContext', {
            configurable: true,
            value: true,
        })
    })

    it('converts WebAuthn browser options into binary buffers', () => {
        const requestOptions = toPublicKeyRequestOptions({
            challenge: 'AQID',
            allowCredentials: [{ id: 'BAUG', type: 'public-key' }],
        })
        const creationOptions = toPublicKeyCreationOptions({
            challenge: 'AQID',
            user: { id: 'BAUG', name: 'admin', displayName: 'Admin' },
            excludeCredentials: [{ id: 'BwgJ', type: 'public-key' }],
        })

        expect(requestOptions.challenge).toBeInstanceOf(ArrayBuffer)
        expect(requestOptions.allowCredentials[0].id).toBeInstanceOf(ArrayBuffer)
        expect(creationOptions.user.id).toBeInstanceOf(ArrayBuffer)
        expect(creationOptions.excludeCredentials[0].id).toBeInstanceOf(ArrayBuffer)
    })

    it('serializes only public WebAuthn credential material', () => {
        expect(serializePasskeyCredential(assertionCredential())).toEqual({
            id: 'credential-id',
            rawId: 'AQID',
            type: 'public-key',
            response: {
                authenticatorData: 'BAUG',
                clientDataJSON: 'BwgJ',
                signature: 'CgsM',
                userHandle: null,
            },
        })
    })

    it.each([
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1', 'iPhone · Safari'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', 'Mac · Chrome'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Edg/130.0', 'Windows · Edge'],
        ['unknown-agent', 'text.passkey.device.this_device'],
    ])('suggests %s as passkey display label', (userAgent, expected) => {
        expect(suggestPasskeyName(userAgent)).toBe(expected)
    })

    it('posts a discoverable login ceremony to the admin passkey routes', async () => {
        const credential = assertionCredential()
        const get = vi.fn().mockResolvedValue(credential)
        Object.defineProperty(window.navigator, 'credentials', {
            configurable: true,
            value: { get },
        })
        ajax.post
            .mockResolvedValueOnce({
                data: {
                    data: {
                        challenge_id: 'login-challenge',
                        options: { challenge: 'AQID', rpId: 'admin.example.test' },
                    },
                },
            })
            .mockResolvedValueOnce({ data: { status: 'success' } })

        await loginWithPasskey()

        expect(get).toHaveBeenCalledWith({
            publicKey: expect.objectContaining({
                challenge: expect.any(ArrayBuffer),
                rpId: 'admin.example.test',
            }),
        })
        expect(ajax.post).toHaveBeenNthCalledWith(2, 'auth/passkey/login', {
            challenge_id: 'login-challenge',
            credential: expect.objectContaining({
                id: 'credential-id',
                rawId: 'AQID',
            }),
        })
    })

    it('posts a named registration ceremony to the authenticated admin routes', async () => {
        const credential = {
            ...assertionCredential(),
            response: {
                attestationObject: buffer(4, 5, 6),
                clientDataJSON: buffer(7, 8, 9),
            },
        }
        const create = vi.fn().mockResolvedValue(credential)
        Object.defineProperty(window.navigator, 'credentials', {
            configurable: true,
            value: { create },
        })
        ajax.post
            .mockResolvedValueOnce({
                data: {
                    data: {
                        challenge_id: 'registration-challenge',
                        options: {
                            challenge: 'AQID',
                            user: { id: 'BAUG', name: 'admin', displayName: 'Admin' },
                        },
                    },
                },
            })
            .mockResolvedValueOnce({ data: { status: 'success' } })

        await registerPasskey('MacBook văn phòng')

        expect(create).toHaveBeenCalledWith({
            publicKey: expect.objectContaining({
                challenge: expect.any(ArrayBuffer),
                user: expect.objectContaining({ id: expect.any(ArrayBuffer) }),
            }),
        })
        expect(ajax.post).toHaveBeenNthCalledWith(2, 'auth/passkeys', {
            challenge_id: 'registration-challenge',
            name: 'MacBook văn phòng',
            credential: expect.objectContaining({
                response: {
                    attestationObject: 'BAUG',
                    clientDataJSON: 'BwgJ',
                },
            }),
        })
    })
})
