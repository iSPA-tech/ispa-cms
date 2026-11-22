<template>
    <div class="menu-workspace">
        <header class="menu-workspace-header">
            <div>
                <h2>{{ form.name || t('text.menu_editor.new-menu') }}</h2>
                <p>
                    {{ t('text.menu_editor.editor-help') }}
                </p>
            </div>
            <div class="menu-actions">
                <span role="status" class="menu-dirty">{{
                    dirty ? t('text.menu_editor.unsaved-changes') : t('text.menu_editor.no-changes')
                }}</span
                ><a-button @click="showPreview = true"
                    ><EyeOutlined /> {{ t('text.menu_editor.preview-structure') }}</a-button
                ><a-button :disabled="saving" @click="cancel">{{ $t('nav.b.cancel') }}</a-button
                ><a-button type="primary" :loading="saving" @click="submit"
                    ><SaveOutlined /> {{ $t('nav.b.save') }}</a-button
                >
            </div>
        </header>
        <a-alert v-if="saveError" type="error" :message="saveError" show-icon class="menu-error" />
        <a-form layout="vertical" class="menu-meta-form">
            <a-form-item :label="$t('nav.a.menus')" required
                ><a-input
                    v-model:value="form.name"
                    :disabled="saving"
                    :aria-label="t('text.menu_editor.menu-name')"
            /></a-form-item>
            <a-form-item :label="t('text.menu_editor.display-location')" required
                ><a-select
                    v-model:value="form.group_key"
                    :disabled="saving"
                    :options="placementOptions"
                    :aria-label="t('text.menu_editor.display-location')"
            /></a-form-item>
            <a-form-item :label="$t('nav.a.languages')" required
                ><a-select
                    v-model:value="form.language_id"
                    :disabled="saving"
                    :options="
                        languages.map((language) => ({ value: language.id, label: language.name }))
                    "
                    :aria-label="$t('nav.a.languages')"
            /></a-form-item>
            <a-form-item :label="$t('validation.attributes.status')"
                ><a-select
                    v-model:value="form.status"
                    :disabled="saving"
                    :options="
                        statuses.map((status) => ({
                            value: status.enum_key,
                            label: locale.startsWith('vi')
                                ? status.name
                                : status.name_alias || status.name,
                        }))
                    "
                    :aria-label="$t('validation.attributes.status')"
            /></a-form-item>
        </a-form>
        <section
            v-if="itemIssues.length"
            ref="errorSummary"
            data-menu-error-summary
            class="menu-validation-summary"
            tabindex="-1"
            role="alert"
            :aria-label="t('text.menu_editor.validation-title')"
        >
            <strong>{{
                t('text.menu_editor.validation-summary', { count: itemIssues.length })
            }}</strong>
            <p>
                {{ t('text.menu_editor.validation-help') }}
            </p>
            <ul>
                <li v-for="issue in itemIssues" :key="issue.key">
                    <button type="button" @click="showIssue(issue)">
                        <strong>{{ issuePath(issue) }}</strong> —
                        {{
                            issue.fields
                                .map((field) =>
                                    fieldError(field, locateMenu(form.items, issue.key)?.node.type)
                                )
                                .join(' ')
                        }}
                    </button>
                </li>
            </ul>
        </section>
        <div class="menu-editor-grid">
            <section class="menu-tree-panel">
                <div class="menu-panel-heading">
                    <div>
                        <h3>{{ t('text.menu_editor.menu-structure') }}</h3>
                        <small>{{ rows.length }} {{ t('text.menu_editor.authored-items') }}</small>
                    </div>
                    <a-button size="small" :disabled="saving" @click="add(null)"
                        ><PlusOutlined /> {{ t('text.menu_editor.add-item') }}</a-button
                    >
                </div>
                <div class="menu-tree-toolbar">
                    <button type="button" @click="expandAll">
                        {{ t('text.menu_editor.expand-all') }}</button
                    ><button type="button" @click="expanded = []">
                        {{ t('text.menu_editor.collapse-all') }}
                    </button>
                </div>
                <div class="menu-tree-body">
                    <a-tree
                        v-if="form.items.length"
                        v-model:expandedKeys="expanded"
                        :selected-keys="selected ? [selected._key] : []"
                        :tree-data="treeData"
                        :show-line="{ showLeafIcon: false }"
                        block-node
                        @select="select"
                    >
                        <template #title="{ key, title }"
                            ><div
                                class="menu-tree-row"
                                :data-menu-key="key"
                                :draggable="!saving"
                                @dragstart.stop="dragStart($event, key)"
                                @dragend="dragEnd"
                            >
                                <HolderOutlined aria-hidden="true" /><span>{{
                                    title || t('text.menu_editor.unnamed')
                                }}</span
                                ><small>{{
                                    typeLabel(locateMenu(form.items, key)?.node.type)
                                }}</small>
                                <small
                                    v-if="issuesByKey.has(key)"
                                    class="menu-node-error"
                                    :title="
                                        issuesByKey
                                            .get(key)
                                            .fields.map((field) =>
                                                fieldError(
                                                    field,
                                                    locateMenu(form.items, key)?.node.type
                                                )
                                            )
                                            .join(' ')
                                    "
                                    >{{ issuesByKey.get(key).fields.length }}
                                    {{ t('text.menu_editor.errors') }}</small
                                >
                                <div v-if="dragKey && dragKey !== key" class="menu-drop-zones">
                                    <span
                                        v-for="zone in zones"
                                        :key="zone.value"
                                        :data-drop-key="`${key}:${zone.value}`"
                                        :class="{ over: hoverZone === `${key}:${zone.value}` }"
                                        @dragenter.prevent.stop
                                        @dragover.prevent.stop="hoverZone = `${key}:${zone.value}`"
                                        @drop.prevent.stop="drop(key, zone.value)"
                                        >{{ zone.label }}</span
                                    >
                                </div>
                            </div></template
                        > </a-tree
                    ><a-empty v-else :description="t('text.menu_editor.empty-menu')" />
                    <div
                        v-if="dragKey"
                        class="menu-root-drop"
                        @dragover.prevent.stop
                        @drop.prevent.stop="drop(null, 'inside')"
                    >
                        {{ t('text.menu_editor.top-level-end-of-menu') }}
                    </div>
                </div>
                <div class="menu-tree-note">
                    <InfoCircleOutlined />
                    {{ t('text.menu_editor.children-help') }}
                </div>
            </section>
            <section ref="detailsPanel" class="menu-details-panel">
                <template v-if="selected"
                    ><div class="menu-panel-heading">
                        <div>
                            <small>{{ t('text.menu_editor.selected-item') }}</small>
                            <h3>{{ selected.name || t('text.menu_editor.new-item') }}</h3>
                        </div>
                        <a-tag>{{ typeLabel(selected.type) }}</a-tag>
                    </div>
                    <div class="menu-item-path">
                        {{ selectedRow?.path
                        }}<button type="button" @click="openMove">
                            {{ t('text.menu_editor.move-item') }}
                        </button>
                    </div>
                    <a-form layout="vertical"
                        ><fieldset :disabled="saving">
                            <menu-item-details
                                :key="selected._key"
                                :item="selected"
                                :categories="categories"
                                :categories-loading="categoriesLoading"
                                :categories-error="categoriesError"
                                :errors="selectedErrors"
                            /></fieldset
                    ></a-form>
                    <div class="menu-details-actions">
                        <a-button :disabled="saving" @click="add(selected._key)"
                            ><PlusOutlined /> {{ t('text.menu_editor.add-child') }}</a-button
                        ><a-button :disabled="saving" @click="openMove"
                            ><SwapOutlined /> {{ t('text.menu_editor.move-to') }}</a-button
                        ><a-button :disabled="saving" @click="duplicate"
                            ><CopyOutlined /> {{ $t('nav.b.duplicate') }}</a-button
                        ><a-popconfirm
                            :title="t('text.menu_editor.remove-confirm')"
                            :ok-text="$t('nav.b.delete')"
                            :cancel-text="$t('nav.b.cancel')"
                            @confirm="remove"
                            ><a-button danger type="text" :disabled="saving"
                                ><DeleteOutlined /> {{ $t('nav.b.delete') }}</a-button
                            ></a-popconfirm
                        >
                    </div> </template
                ><a-empty v-else :description="t('text.menu_editor.select-item')" />
            </section>
        </div>

        <a-modal
            v-model:open="showMove"
            :title="t('text.menu_editor.move-item')"
            :ok-text="t('text.menu_editor.move-item')"
            :cancel-text="$t('nav.b.cancel')"
            @ok="move"
            ><p>{{ selected?.name }}</p>
            <a-form layout="vertical"
                ><a-form-item :label="t('text.menu_editor.parent-item')"
                    ><a-select
                        v-model:value="moveParent"
                        :options="moveOptions"
                        show-search
                        option-filter-prop="label"
                        :aria-label="t('text.menu_editor.new-parent')" /></a-form-item
                ><a-form-item :label="t('text.menu_editor.move-position')"
                    ><a-radio-group v-model:value="movePosition"
                        ><a-radio value="first">{{ t('text.menu_editor.first') }}</a-radio
                        ><a-radio value="last">{{
                            t('text.menu_editor.last')
                        }}</a-radio></a-radio-group
                    ></a-form-item
                ></a-form
            ></a-modal
        >
        <a-drawer
            v-model:open="showPreview"
            :title="t('text.menu_editor.configured-structure')"
            width="min(590px, 100vw)"
            ><p>
                {{ t('text.menu_editor.preview-help') }}
            </p>
            <div
                v-for="(row, index) in previewRows"
                :key="index"
                class="menu-preview-row"
                :style="{ marginLeft: `${Math.min(row.depth, 8) * 16}px` }"
            >
                <strong>{{ row.node.name }}</strong
                ><a-tag v-if="row.node.automatic" color="green">{{
                    t('text.menu_editor.automatic')
                }}</a-tag
                ><code>{{ row.node.url }}</code>
            </div></a-drawer
        >
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'
import { Modal, message } from 'ant-design-vue'
import {
    CopyOutlined,
    DeleteOutlined,
    EyeOutlined,
    HolderOutlined,
    InfoCircleOutlined,
    PlusOutlined,
    SaveOutlined,
    SwapOutlined,
} from '@ant-design/icons-vue'
import MenuItemDetails from '@/components/menu-item-details.vue'
import { flattenMenu, locateMenu, menuNodeKey, moveMenuItem } from '@/components/menu-tree-state'
import {
    duplicateMenuBranch,
    menuPrefix,
    menuUrl,
    newMenuItem,
    removedMenuIds,
    serializeMenuItems,
    menuItemIssues,
} from '@/pages/setting/menu-editor-state'

const props = defineProps({
    form: { type: Object, required: true },
    languages: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    categoriesLoading: Boolean,
    categoriesError: Boolean,
    saving: Boolean,
    saveError: String,
    deletedIds: { type: Array, default: () => [] },
})
const emit = defineEmits(['save', 'cancel', 'remove'])
const { t, locale } = useI18n()
const signature = () =>
    JSON.stringify({
        name: props.form.name,
        group_key: props.form.group_key,
        language_id: props.form.language_id,
        status: props.form.status,
        items: serializeMenuItems(props.form.items),
    })
const baseline = signature()
const dirty = computed(() => signature() !== baseline || props.deletedIds.length > 0)
const selectedKey = ref(props.form.items[0]?._key || null)
const rows = computed(() => flattenMenu(props.form.items))
const validationAttempted = ref(false)
const itemIssues = computed(() =>
    validationAttempted.value ? menuItemIssues(props.form.items) : []
)
const issuesByKey = computed(() => new Map(itemIssues.value.map((issue) => [issue.key, issue])))
const errorSummary = ref(null)
const detailsPanel = ref(null)
const selectedErrors = computed(() =>
    Object.fromEntries(
        (issuesByKey.value.get(selectedKey.value)?.fields || []).map((field) => [
            field,
            fieldError(field, selected.value?.type),
        ])
    )
)
function issuePath(issue) {
    const path = issue.names
        .map(
            (name, index) =>
                name?.trim() || `${t('text.menu_editor.unnamed-item')} #${issue.position[index]}`
        )
        .join(' › ')
    return `${issue.position.join('.')} · ${path} (${t('text.menu_editor.level')} ${issue.position.length})`
}
function fieldError(field, type) {
    if (field === 'name') return t('text.menu_editor.name-required')
    if (field === 'type') return t('text.menu_editor.type-required')
    if (field === 'level') return t('text.menu_editor.depth-invalid')
    if (type === 'category') return t('text.menu_editor.category-link-required')
    if (type === 'post') return t('text.menu_editor.post-link-required')
    return t('text.menu_editor.url-required')
}
async function showIssue(issue, focusField = true) {
    if (props.saving) return
    selectedKey.value = issue.key
    let row = rows.value.find(({ node }) => node._key === issue.key)
    const ancestors = []
    while (row?.parentId) {
        ancestors.push(row.parentId)
        row = rows.value.find(({ node }) => node._key === row.parentId)
    }
    expanded.value = [...new Set([...expanded.value, ...ancestors])]
    await nextTick()
    if (focusField) {
        const field = detailsPanel.value?.querySelector(`[data-menu-field="${issue.fields[0]}"]`)
        field?.scrollIntoView?.({ block: 'center' })
        field?.querySelector('input, button, [tabindex]')?.focus()
    }
}
const selected = computed(() => locateMenu(props.form.items, selectedKey.value)?.node || null)
const selectedRow = computed(() =>
    rows.value.find(({ node }) => menuNodeKey(node) === selectedKey.value)
)
const expanded = ref(rows.value.map(({ node }) => node._key))
const treeData = computed(() => {
    const map = (node) => ({ key: node._key, title: node.name, children: node.items.map(map) })
    return props.form.items.map(map)
})
const placementOptions = computed(() => {
    const options = [
        'main_menu',
        'footer_menu',
        'user_menu',
        'secondary_menu',
        'main_mobile',
        'secondary_footer',
    ].map((value) => ({
        value,
        label: t(`text.menu.${value === 'secondary_footer' ? 'secondary_footer_menu' : value}`),
    }))
    if (props.form.group_key && !options.some(({ value }) => value === props.form.group_key))
        options.push({ value: props.form.group_key, label: props.form.group_key })
    return options
})
function typeLabel(type) {
    return t(
        {
            url: 'text.custom-url',
            category: 'nav.a.categories',
            post: 'nav.a.posts',
            gallery: 'nav.a.galleries',
            place: 'nav.a.places',
            product: 'nav.a.products',
        }[type] || 'text.menu-type'
    )
}
const showMove = ref(false)
const showPreview = ref(false)
const moveParent = ref('root')
const movePosition = ref('last')
const moveOptions = computed(() => {
    const excluded = new Set(
        selected.value ? flattenMenu([selected.value]).map(({ node }) => node._key) : []
    )
    return [
        { value: 'root', label: t('text.menu_editor.top-level') },
        ...rows.value
            .filter(({ node }) => !excluded.has(node._key))
            .map(({ node, path }) => ({ value: node._key, label: path })),
    ]
})
const dragKey = ref(null)
const hoverZone = ref(null)
const zones = computed(() => [
    { value: 'before', label: t('text.menu_editor.before') },
    { value: 'inside', label: t('text.menu_editor.inside') },
    { value: 'after', label: t('text.menu_editor.after') },
])
function select(keys) {
    if (keys.length) selectedKey.value = keys[0]
}
function expandAll() {
    expanded.value = rows.value.map(({ node }) => node._key)
}
function add(parent) {
    const item = newMenuItem()
    const items = parent ? locateMenu(props.form.items, parent).node.items : props.form.items
    items.push(item)
    selectedKey.value = item._key
    if (parent) expanded.value = [...new Set([...expanded.value, parent])]
}
function dragStart(event, key) {
    if (props.saving) return
    dragKey.value = key
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', key)
}
function dragEnd() {
    dragKey.value = null
    hoverZone.value = null
}
function drop(target, position) {
    if (props.saving) return
    const key = dragKey.value
    if (moveMenuItem(props.form.items, key, target, position)) {
        selectedKey.value = key
        if (target) expanded.value = [...new Set([...expanded.value, target])]
    } else message.warning(t('text.menu_editor.move-invalid'))
    dragEnd()
}
function openMove() {
    moveParent.value = selectedRow.value?.parentId || 'root'
    movePosition.value = 'last'
    showMove.value = true
}
function move() {
    const parent = moveParent.value === 'root' ? null : moveParent.value
    if (!moveMenuItem(props.form.items, selectedKey.value, parent)) return
    if (movePosition.value === 'first') {
        const list = parent ? locateMenu(props.form.items, parent).node.items : props.form.items
        list.unshift(list.pop())
    }
    if (parent) expanded.value = [...new Set([...expanded.value, parent])]
    showMove.value = false
}
function duplicate() {
    const found = locateMenu(props.form.items, selectedKey.value)
    const copy = duplicateMenuBranch(found.node)
    copy.name = `${copy.name} (${t('text.menu_editor.copy')})`
    found.siblings.splice(found.index + 1, 0, copy)
    selectedKey.value = copy._key
}
function remove() {
    const found = locateMenu(props.form.items, selectedKey.value)
    emit('remove', removedMenuIds(found.node))
    found.siblings.splice(found.index, 1)
    selectedKey.value = props.form.items[0]?._key || null
}
async function submit() {
    if (props.saving) return
    validationAttempted.value = true
    if (!props.form.name?.trim() || !props.form.group_key || !props.form.language_id) {
        message.error(t('text.menu_editor.metadata-required'))
        return
    }
    if (itemIssues.value.length) {
        await showIssue(itemIssues.value[0], false)
        errorSummary.value?.focus()
        errorSummary.value?.scrollIntoView?.({ block: 'center' })
        return
    }
    if (!props.form.items.length) {
        message.error(t('text.menu_editor.item-required'))
        return
    }
    for (const { node } of rows.value) {
        if (!Number(node.id) && !node.title) node.title = node.name
    }
    emit('save')
}
function cancel() {
    if (!dirty.value) {
        emit('cancel')
        return
    }
    Modal.confirm({
        title: t('text.menu_editor.discard-unsaved-changes'),
        okText: t('text.menu_editor.discard'),
        cancelText: t('text.menu_editor.keep-editing'),
        onOk: () => emit('cancel'),
    })
}
const categoryNodes = computed(() => {
    const map = new Map()
    const walk = (nodes) =>
        nodes.forEach((node) => {
            map.set(Number(node.id), node)
            walk(node.children || [])
        })
    walk(props.categories)
    return map
})
const previewRows = computed(() => {
    const generated = (category, prefix, depth, max, visited) => {
        if (max > 0 && depth > max) return []
        return (category?.children || [])
            .filter((node) => node.status === 'status_published' && !visited.has(Number(node.id)))
            .map((node) => ({
                name: node.name,
                url: prefix
                    ? `/${prefix.replace(/^\/+|\/+$/g, '')}/${node.slug}`
                    : node.route || node.slug,
                automatic: true,
                items: generated(
                    node,
                    prefix,
                    depth + 1,
                    max,
                    new Set([...visited, Number(node.id)])
                ),
            }))
    }
    const map = (node) => ({
        name: node.name,
        url: menuUrl(
            node,
            node.type === 'category'
                ? categoryNodes.value.get(Number(node.target_id)) || node.target
                : node.target,
            locale.value
        ),
        items: [
            ...node.items.map(map),
            ...(node.type === 'category' && node.custom.isExpandChild
                ? generated(
                      categoryNodes.value.get(Number(node.target_id)),
                      menuPrefix(node, locale.value),
                      1,
                      node.custom.expandChildLevel ?? 2,
                      new Set([Number(node.target_id)])
                  )
                : []),
        ],
    })
    return flattenMenu(props.form.items.map(map))
})
function beforeClose(event) {
    if (dirty.value) {
        event.preventDefault()
        event.returnValue = ''
    }
}
window.addEventListener('beforeunload', beforeClose)
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeClose))
onBeforeRouteLeave(() => {
    if (props.saving) return false
    if (!dirty.value) return true
    return new Promise((resolve) =>
        Modal.confirm({
            title: t('text.menu_editor.discard-unsaved-changes'),
            okText: t('text.menu_editor.discard'),
            cancelText: t('text.menu_editor.keep-editing'),
            onOk: () => resolve(true),
            onCancel: () => resolve(false),
        })
    )
})
</script>

<style src="./menu-editor.css"></style>
