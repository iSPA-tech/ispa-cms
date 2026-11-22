import { onBeforeUnmount, ref } from 'vue'
import { createAuthenticatorQrUrl, revokeAuthenticatorQrUrl } from '@/libs/authenticator'

export const useAuthenticatorQr = () => {
    const authenticatorQrUrl = ref('')
    const authenticatorQrError = ref(false)
    let requestVersion = 0

    const clearAuthenticatorQr = () => {
        requestVersion += 1
        revokeAuthenticatorQrUrl(authenticatorQrUrl.value)
        authenticatorQrUrl.value = ''
        authenticatorQrError.value = false
    }

    const renderAuthenticatorQr = async (otpAuthUri) => {
        clearAuthenticatorQr()

        if (!otpAuthUri) {
            return
        }

        const currentRequest = ++requestVersion

        try {
            const qrUrl = await createAuthenticatorQrUrl(otpAuthUri)

            if (currentRequest === requestVersion) {
                authenticatorQrUrl.value = qrUrl
            } else {
                revokeAuthenticatorQrUrl(qrUrl)
            }
        } catch {
            if (currentRequest === requestVersion) {
                authenticatorQrError.value = true
            }
        }
    }

    onBeforeUnmount(clearAuthenticatorQr)

    return {
        authenticatorQrError,
        authenticatorQrUrl,
        clearAuthenticatorQr,
        renderAuthenticatorQr,
    }
}
