import ajax from '@/ajax'

export const validateExpression = async (expression, payload = {}) => {
    const body = { expression, ...payload }
    const res = await ajax.post('workflow/expression/validate', body)

    return res?.data
}

export const previewTemplate = async (template, payload = {}) => {
    const body = { template, ...payload }
    const res = await ajax.post('workflow/template/preview', body)

    return res?.data
}

export const fetchModelSchema = async (model) => {
    const res = await ajax.get('workflow/schema', {
        params: { model },
    })

    return res?.data?.data || res?.data
}

export const fetchRelationSchema = async (model, relation) => {
    const res = await ajax.get('workflow/schema/relation', {
        params: { model, relation },
    })

    return res?.data?.data || res?.data
}

export const fetchModelList = async () => {
    const res = await ajax.get('workflow/models')

    return res?.data?.data || res?.data || []
}
