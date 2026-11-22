<template>
    <div class="menu-item-details">
        <a-form-item
            data-menu-field="name"
            :validate-status="errors.name ? 'error' : undefined"
            :label="t('text.menu_editor.display-name')"
            required
        >
            <a-input
                v-model:value="item.name"
                :aria-label="t('text.menu_editor.display-name')"
                :maxlength="255"
                :aria-invalid="Boolean(errors.name)"
                :aria-describedby="errors.name ? errorId('name') : undefined"
            />
            <template v-if="errors.name" #help
                ><span :id="errorId('name')">{{ errors.name }}</span></template
            >
        </a-form-item>
        <a-form-item
            data-menu-field="type"
            :validate-status="errors.type ? 'error' : undefined"
            :label="t('text.menu_editor.link-to')"
        >
            <a-select
                :value="item.type"
                :options="typeOptions"
                @change="changeType"
                :aria-label="t('text.menu_editor.link-type')"
                :aria-invalid="Boolean(errors.type)"
                :aria-describedby="errors.type ? errorId('type') : undefined"
            />
            <template v-if="errors.type" #help
                ><span :id="errorId('type')">{{ errors.type }}</span></template
            >
        </a-form-item>
        <template v-if="item.type === 'category'">
            <a-form-item
                data-menu-field="link"
                :validate-status="errors.link ? 'error' : undefined"
                :label="$t('nav.a.categories')"
            >
                <a-spin :spinning="categoriesLoading">
                    <a-cascader
                        :value="categoryPath"
                        :options="categories"
                        :field-names="{ label: 'name', value: 'id', children: 'children' }"
                        change-on-select
                        show-search
                        :disabled="!rule('category.viewAny')"
                        :placeholder="$t('text.select')"
                        @change="chooseCategory"
                        :aria-label="t('text.menu_editor.choose-category')"
                        :aria-invalid="Boolean(errors.link)"
                        :aria-describedby="errors.link ? errorId('link') : undefined"
                    />
                </a-spin>
                <a-alert
                    v-if="categoriesError"
                    type="error"
                    :message="t('text.menu_editor.categories-error')"
                    show-icon
                />
                <div v-else-if="!categoriesLoading && !categories.length" class="field-hint">
                    {{ t('text.menu_editor.categories-empty') }}
                </div>
                <template v-if="errors.link" #help
                    ><span :id="errorId('link')">{{ errors.link }}</span></template
                >
            </a-form-item>
            <div class="menu-setting-line">
                <span>{{ $t('text.menu-expand-child') }}</span
                ><a-switch
                    v-model:checked="item.custom.isExpandChild"
                    :aria-label="$t('text.menu-expand-child')"
                />
            </div>
            <template v-if="item.custom.isExpandChild">
                <div class="menu-setting-line">
                    <span>{{ $t('text.menu-expand-all-child') }}</span
                    ><a-switch
                        :checked="level === 0"
                        @change="toggleAll"
                        :aria-label="$t('text.menu-expand-all-child')"
                    />
                </div>
                <a-form-item
                    v-if="level !== 0"
                    data-menu-field="level"
                    :validate-status="errors.level ? 'error' : undefined"
                    :label="$t('text.menu-expand-child-level')"
                >
                    <a-input-number
                        :value="level"
                        :min="1"
                        :precision="0"
                        @change="item.custom.expandChildLevel = $event"
                        :aria-label="$t('text.menu-expand-child-level')"
                        :aria-invalid="Boolean(errors.level)"
                        :aria-describedby="errors.level ? errorId('level') : undefined"
                    />
                    <span class="field-hint">{{ t('text.menu_editor.depth-help') }}</span>
                    <template v-if="errors.level" #help
                        ><span :id="errorId('level')">{{ errors.level }}</span></template
                    >
                </a-form-item>
            </template>
        </template>
        <a-form-item
            v-else-if="item.type === 'post'"
            data-menu-field="link"
            :validate-status="errors.link ? 'error' : undefined"
            :label="$t('nav.a.posts')"
        >
            <a-select
                :value="item.target_id || undefined"
                show-search
                :filter-option="false"
                :loading="postsLoading"
                :options="postOptions"
                :disabled="!rule('post.viewAny')"
                @search="searchPosts"
                @change="choosePost"
                :aria-label="t('text.menu_editor.choose-post')"
                :aria-invalid="Boolean(errors.link)"
                :aria-describedby="errors.link ? errorId('link') : undefined"
            />
            <a-alert
                v-if="postError"
                type="error"
                :message="t('text.menu_editor.posts-error')"
                show-icon
            />
            <template v-if="errors.link" #help
                ><span :id="errorId('link')">{{ errors.link }}</span></template
            >
        </a-form-item>
        <a-form-item
            v-if="item.type === 'url' || !['category', 'post'].includes(item.type)"
            :label="$t('text.menu-url')"
            required
            data-menu-field="link"
            :validate-status="errors.link ? 'error' : undefined"
        >
            <a-input
                v-model:value="item.url"
                :aria-label="$t('text.menu-url')"
                :aria-invalid="Boolean(errors.link)"
                :aria-describedby="errors.link ? errorId('link') : undefined"
            />
            <div v-if="item.type !== 'url'" class="field-hint">
                {{ t('text.menu_editor.legacy-link-help') }}
            </div>
            <template v-if="errors.link" #help
                ><span :id="errorId('link')">{{ errors.link }}</span></template
            >
        </a-form-item>
        <div class="menu-setting-line">
            <span>{{ t('text.menu_editor.open-in-a-new-tab') }}</span
            ><a-switch
                :checked="item.custom.action === '_blank'"
                @change="item.custom.action = $event ? '_blank' : '_self'"
                :aria-label="t('text.menu_editor.open-in-a-new-tab')"
            />
        </div>
        <div class="menu-url-preview">
            <span>{{ t('text.menu_editor.link-preview') }}</span
            ><code>{{ resolvedUrl || t('text.menu_editor.no-link-selected') }}</code
            ><small>{{ t('text.menu_editor.source-route-help') }}</small>
        </div>
        <details class="menu-advanced">
            <summary>
                {{ t('text.menu_editor.advanced-routing-and-appearance') }}
            </summary>
            <div class="menu-details-grid">
                <a-form-item :label="t('text.menu_editor.override-path-prefix')"
                    ><a-input
                        :value="prefix"
                        @change="item.custom.prefix = $event.target.value"
                        placeholder="/chuyen-muc"
                        :aria-label="t('text.menu_editor.override-path-prefix')"
                    />
                    <div class="field-hint">
                        {{ t('text.menu_editor.prefix-help') }}
                    </div></a-form-item
                >
                <a-form-item :label="$t('text.menu-title')"
                    ><a-input v-model:value="item.title" :aria-label="$t('text.menu-title')"
                /></a-form-item>
                <a-form-item :label="$t('text.menu-icon')"
                    ><icon-picker
                        v-model="item.custom.icon"
                        overlay-class-name="menu-icon-picker-popup"
                        :search-placeholder="t('text.menu_editor.search-icons')"
                        :empty-text="t('text.menu_editor.no-icons')"
                        :placeholder="$t('text.menu-icon')"
                        :aria-label="$t('text.menu-icon')"
                /></a-form-item>
                <a-form-item :label="$t('text.menu-color')"
                    ><a-input
                        v-model:value="item.custom.color"
                        :aria-label="$t('text.menu-color')"
                        placeholder="#336699"
                        allow-clear
                    >
                        <template #prefix
                            ><input
                                type="color"
                                class="menu-color-swatch"
                                :value="colorSwatch"
                                :aria-label="t('text.menu_editor.choose-text-color')"
                                @input="item.custom.color = $event.target.value"
                        /></template> </a-input
                ></a-form-item>
            </div>
        </details>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { debounce } from 'lodash-es'
import { searchFilterPosts } from '@/libs'
import { rule } from '@/mixins'
import { menuPrefix, menuUrl } from '@/pages/setting/menu-editor-state'
import IconPicker from '@/components/icon-picker.vue'

const props = defineProps({
    item: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    categoriesLoading: Boolean,
    categoriesError: Boolean,
    errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['target-change'])
const { t, locale } = useI18n()
const errorId = (field) => `${props.item._key}-${field}-error`
const level = computed(() =>
    Object.hasOwn(props.item.custom, 'expandChildLevel') ? props.item.custom.expandChildLevel : 2
)
const prefix = computed(() => menuPrefix(props.item, locale.value))
const colorSwatch = computed(() => {
    const color = props.item.custom.color || ''
    if (/^#[\da-f]{6}$/i.test(color)) return color
    return /^#[\da-f]{3}$/i.test(color)
        ? '#' +
              color
                  .slice(1)
                  .split('')
                  .map((digit) => digit + digit)
                  .join('')
        : '#000000'
})
const source = computed(
    () => findCategory(props.categories, props.item.target_id)?.node || props.item.target
)
const resolvedUrl = computed(() =>
    menuUrl(
        props.item,
        props.item.type === 'category' ? source.value : props.item.target,
        locale.value
    )
)
const posts = ref([])
const postsLoading = ref(false)
const postError = ref(false)
let postSequence = 0
const types = [
    ['url', 'text.custom-url', ''],
    ['category', 'nav.a.categories', 'category.viewAny'],
    ['post', 'nav.a.posts', 'post.viewAny'],
    ['gallery', 'nav.a.galleries', 'gallery.viewAny'],
    ['place', 'nav.a.places', 'place.viewAny'],
    ['product', 'nav.a.products', 'product.viewAny'],
]
const typeOptions = computed(() => {
    const options = types.map(([value, label, permission]) => ({
        value,
        label: t(label),
        disabled: Boolean(permission && !rule(permission)),
    }))
    if (!options.some(({ value }) => value === props.item.type))
        options.push({ value: props.item.type, label: props.item.type })
    return options
})
function findCategory(nodes, id, trail = []) {
    for (const node of nodes) {
        const path = [...trail, node.id]
        if (Number(node.id) === Number(id)) return { node, path }
        const child = findCategory(node.children || [], id, path)
        if (child) return child
    }
    return null
}
const categoryPath = computed(
    () => findCategory(props.categories, props.item.target_id)?.path || []
)
const postOptions = computed(() => {
    const options = posts.value.map((node) => ({
        value: Number(node.id),
        label: node.name || node.title,
    }))
    if (
        props.item.target_id &&
        !options.some(({ value }) => value === Number(props.item.target_id))
    )
        options.unshift({
            value: Number(props.item.target_id),
            label:
                props.item.target?.name ||
                `${t('text.menu_editor.current-linked-post')}: ${props.item.name || props.item.url}`,
        })
    return options
})
function changeType(type) {
    if (type === props.item.type) return
    Object.assign(props.item, {
        type,
        target_id: 0,
        target_type:
            type === 'url' ? '' : `App\\Models\\${type.charAt(0).toUpperCase() + type.slice(1)}`,
        url: '',
        target: null,
    })
    props.item.custom.prefix = ''
    props.item.custom.isExpandChild = false
    props.item.custom.expandChildLevel = 2
    props.item.custom.slugs = {}
    props.item.custom.categoryId = []
}
function chooseCategory(path) {
    const target = path?.length ? findCategory(props.categories, path.at(-1))?.node : null
    if (!target) return
    props.item.target_id = Number(target.id)
    props.item.target_type = 'App\\Models\\Category'
    props.item.url = target.slug || ''
    props.item.custom.categoryId = path
    props.item.custom.slugs = target.slugs || {}
    props.item.target = { id: target.id, name: target.name, slug: target.slug, route: target.route }
    if (!props.item.name) props.item.name = target.name
    if (!props.item.title) props.item.title = target.name
    emit('target-change')
}
function choosePost(id) {
    const target = posts.value.find((node) => Number(node.id) === Number(id))
    if (!target) return
    props.item.target_id = Number(id)
    props.item.target_type = 'App\\Models\\Post'
    props.item.url = target.slug || ''
    props.item.target = { id, name: target.name, slug: target.slug, route: target.route }
    if (!props.item.name) props.item.name = target.name
    if (!props.item.title) props.item.title = target.name
}
async function fetchPosts(query = '') {
    if (props.item.type !== 'post' || !rule('post.viewAny')) return
    const sequence = ++postSequence
    postsLoading.value = true
    postError.value = false
    try {
        const data = await searchFilterPosts({ query, type: 'all', limit: 30 })
        if (sequence === postSequence) posts.value = data.data || (Array.isArray(data) ? data : [])
    } catch {
        if (sequence === postSequence) postError.value = true
    } finally {
        if (sequence === postSequence) postsLoading.value = false
    }
}
const searchPosts = debounce(fetchPosts, 300)
watch(
    () => [props.item._key, props.item.type],
    () => {
        searchPosts.cancel()
        posts.value = []
        if (props.item.type === 'post') fetchPosts()
    },
    { immediate: true }
)
function toggleAll(enabled) {
    props.item.custom.expandChildLevel = enabled ? 0 : 2
}
onBeforeUnmount(() => {
    searchPosts.cancel()
    postSequence++
})
</script>
