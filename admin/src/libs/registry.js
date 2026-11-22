import ajax from '@/ajax'

/**
 * Fetch the backend admin registry: the enabled modules that declare `admin.navigation`, filtered to what the
 * signed-in person may see. The request is silent: a failed call must never raise a toast (the bundle menu stays).
 *
 * @returns {Promise<{schema_version: number, modules: Array<object>}>} Registry payload; an empty module list when the response has none.
 */
export const getBackendRegistry = async () => {
    const res = await ajax.get('registry', { silent: true })
    const data = res?.data
    return {
        schema_version: data?.schema_version ?? 1,
        modules: Array.isArray(data?.modules) ? data.modules : [],
    }
}
