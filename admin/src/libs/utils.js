/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const getProjectList = async () => {
    return await ajax.get('utilities/projects').then((res) => {
        return res.data.data || []
    })
}

export const getModuleList = async () => {
    return await ajax.get('utilities/modules/list').then((res) => {
        return res.data.data || []
    })
}

export function getUpstreamStoreAsKeys(workflow, activeNodeId) {
    if (!workflow || !activeNodeId) return []

    // 1. build adjacency (reverse)
    const parentsMap = {}
    for (const edge of workflow.edges) {
        if (!parentsMap[edge.target]) {
            parentsMap[edge.target] = []
        }
        parentsMap[edge.target].push(edge.source)
    }

    // 2. DFS lấy toàn bộ ancestor node ids
    const visited = new Set()
    const stack = [...(parentsMap[activeNodeId] || [])]

    while (stack.length) {
        const id = stack.pop()
        if (visited.has(id)) continue
        visited.add(id)

        const parents = parentsMap[id] || []
        stack.push(...parents)
    }

    // 3. collect storeAs
    const vars = []

    for (const node of workflow.nodes) {
        if (!visited.has(node.id)) continue

        const storeAs = node.config?.storeAs
        if (typeof storeAs === 'string' && storeAs.startsWith('vars.')) {
            vars.push(storeAs)
        }
    }

    return vars
}
