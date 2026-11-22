import ajax from '@/ajax'

export const getOptionSources = async () => {
    const response = await ajax.get('/field/option-sources')
    return response.data?.data || []
}

export const getOptionSourceOptions = async (source, params = {}) => {
    const response = await ajax.get(`/field/option-sources/${encodeURIComponent(source)}/options`, {
        params,
    })
    return response.data?.data || response.data
}
