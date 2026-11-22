/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */
import ajax from '@/ajax'
import { cleanNameTranslations } from '@/libs/widget-names'

const module = 'widget'

export const searchFilterWidgets = async (params, config = {}) => {
    return await ajax.get(`${module}/search`, { params: params, ...config }).then((res) => {
        return res.data.data || []
    })
}

export const getWidgets = async (params) => {
    return await ajax
        .get(module + (params.isAdmin ? '/admin-list' : ''), { params: params })
        .then((res) => {
            return res.data.data || {}
        })
}

// Kept for other flows that still perform an immediate nested delete; the
// staged widget editor flow (widget.vue + widget-template.vue/widget-input.vue)
// no longer calls these directly - deletions are staged locally and only
// committed through the aggregate `updateWidget` payload.
export const delWidgetTemplate = async (widgetId = 0, templateId = 0) => {
    return await ajax.delete(`${module}/${widgetId}/template/${templateId}`).then((res) => {
        return res.data.data || {}
    })
}

export const delWidgetInput = async (widgetId = 0, inputId = 0) => {
    return await ajax.delete(`${module}/${widgetId}/inputs/${inputId}`).then((res) => {
        return res.data || {}
    })
}

export const deleteWidget = async (id = 0) => {
    return await ajax.delete(`${module}/${id}`).then((res) => res.data || {})
}

export const storeWidget = async (data = {}) => {
    return await ajax.post(module, data).then((res) => res.data || {})
}

export const updateWidget = async (id, data = {}) => {
    return await ajax.put(`${module}/${data.id}`, data).then((res) => res.data || {})
}

/**
 * Build the aggregate PUT/POST payload for a widget save.
 *
 * `template`/`inputs` arrays exclude rows staged for deletion (`_deleted`)
 * and the local-only `_deleted` marker is stripped from every wire row.
 *
 * Deletion ids are the union of:
 * - ids explicitly staged for deletion by the user (`_deleted` rows), and
 * - persisted snapshot ids missing from the surviving arrays. The input
 *   rebuild (getInputVariableByTemplate) runs on every form change and can
 *   silently drop persisted rows whose template variable disappeared; when
 *   deletion fields are present the backend sweep is disabled, so dropped
 *   rows must be reported explicitly or they become invisible DB orphans.
 *
 * `deleted_template_ids`/`deleted_input_ids` are ONLY added when non-empty -
 * omitting them preserves the backend's legacy `whereNotIn` sweep behaviour
 * when nothing was deleted.
 *
 * `name_translations` / `description_translations` (widget) and `name_translations` (each design) are sent in the
 * API's normal form: `{vi?, en?}` without blanks, or null.
 *
 * @param {Object} form - widget form state (id, name, template[], inputs[], ...)
 * @param {Object} persisted - snapshot of persisted child ids taken at edit
 *                             load time: { templateIds: [], inputIds: [] }
 * @returns {Object} API-ready payload
 */
export const buildWidgetSavePayload = (form = {}, persisted = {}) => {
    const template = Array.isArray(form.template) ? form.template : []
    const inputs = Array.isArray(form.inputs) ? form.inputs : []
    const persistedTemplateIds = Array.isArray(persisted.templateIds) ? persisted.templateIds : []
    const persistedInputIds = Array.isArray(persisted.inputIds) ? persisted.inputIds : []

    const survivingTemplate = template.filter((item) => !item?._deleted)
    const survivingInputs = inputs.filter((item) => !item?._deleted)

    const collectDeletedIds = (rows, survivingRows, persistedIds) => {
        const stagedIds = rows
            .filter((item) => item?._deleted && item.id > 0)
            .map((item) => item.id)
        const survivingIds = new Set(survivingRows.map((item) => item?.id).filter((id) => id > 0))
        const droppedIds = persistedIds.filter((id) => id > 0 && !survivingIds.has(id))

        return [...new Set([...stagedIds, ...droppedIds])]
    }

    const deletedTemplateIds = collectDeletedIds(template, survivingTemplate, persistedTemplateIds)
    const deletedInputIds = collectDeletedIds(inputs, survivingInputs, persistedInputIds)

    // Strip the local-only staging marker from wire rows.
    const stripStagingMarker = ({ _deleted, ...rest }) => rest
    // Names per language go out without blanks; a row that never had the field is sent as it was.
    const withCleanTranslations = (row, fields) => {
        const out = { ...row }
        for (const field of fields) {
            if (field in out) out[field] = cleanNameTranslations(out[field])
        }
        return out
    }

    const payload = {
        ...withCleanTranslations(form, ['name_translations', 'description_translations']),
        template: survivingTemplate.map((row) => withCleanTranslations(stripStagingMarker(row), ['name_translations'])),
        inputs: survivingInputs.map(stripStagingMarker),
    }

    if (deletedTemplateIds.length > 0) {
        payload.deleted_template_ids = deletedTemplateIds
    }

    if (deletedInputIds.length > 0) {
        payload.deleted_input_ids = deletedInputIds
    }

    return payload
}

export const getWidget = async (id, edit = false) => {
    return await ajax.get(`${module}/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const getWidgetPreview = async (id, params = {}, config = {}) => {
    return await ajax.post(`${module}/${id}/preview`, params, config).then((res) => res.data || '')
}

export const getTemplateByWidget = async (id) => {
    return await ajax.get(`${module}/${id}/template`).then((res) => res.data || {})
}

// `config` goes to the request as it is, e.g. `{ silent: true }` for a caller that handles a refused read itself.
export const getInputByWidget = async (id, config = {}) => {
    return await ajax.get(`${module}/${id}/inputs`, config).then((res) => res.data || {})
}

export const getUtilComponents = async () => {
    return await ajax.get(`utilities/components`).then((res) => res.data || {})
}

const inputModule = 'widget-input'

export const getInputs = async (params) => {
    return await ajax.get(inputModule, { params: params }).then((res) => {
        return res.data.data || {}
    })
}

const templateModule = 'widget-template'

export const getTemplates = async (params) => {
    return await ajax.get(templateModule, { params: params }).then((res) => {
        return res.data.data || {}
    })
}
