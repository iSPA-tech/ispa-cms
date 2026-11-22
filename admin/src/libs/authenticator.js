import ajax from '@/ajax'

export const createAuthenticatorQrUrl = async (otpAuthUri) => {
    const response = await ajax.post(
        'qr',
        { string: otpAuthUri, size: 280 },
        { responseType: 'blob' }
    )

    return window.URL.createObjectURL(response.data)
}

export const revokeAuthenticatorQrUrl = (url) => {
    if (url) {
        window.URL.revokeObjectURL(url)
    }
}
