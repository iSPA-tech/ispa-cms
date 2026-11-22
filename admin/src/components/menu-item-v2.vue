<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-lg transition transform duration-200">
        <div class="flex items-center p-2 border leading-6 text-sm bg-white cursor-pointer">
            <span>
                <svg
                    class="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                    />
                </svg>
            </span>
            <span class="pl-2 text-ellipsis max-w-full">
                {{ innerElement.name || $t('text.ui.menu-item.default_name') }}
                <em v-if="innerElement.type !== ''" class="text-gray-400 text-xs">
                    ({{ innerElement.type }})
                </em>
            </span>
            <a-tooltip :title="$t('nav.b.duplicate')">
                <span
                    class="cursor-pointer text-blue-500 hover:text-blue-700 ml-3 flex items-center"
                    role="button"
                    @click="duplicateElement"
                >
                    <i-icon icon="content-copy" class="size-5" />
                </span>
            </a-tooltip>

            <span
                class="cursor-pointer text-green-500 hover:text-green-700 flex items-center space-x-1 flex-1"
                role="button"
                @click="innerElement.isShow = !innerElement.isShow"
            >
                <span class="flex-1"></span>
                <span>{{ $t('nav.b.edit') }}</span>
                <span>
                    <svg
                        class="h-4 w-4"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            clip-rule="evenodd"
                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                            fill-rule="evenodd"
                        />
                    </svg>
                </span>
            </span>
        </div>
        <transition class="py-4" name="accordion">
            <div
                v-if="innerElement && innerElement.isShow"
                class="px-2 pt-2 border-b border-dashed bg-gray-100"
            >
                <a-form-item
                    :label="$t('text.menu-name')"
                    :name="[...innerName, 'name']"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', {
                                a: $t('text.menu-name'),
                            }),
                        },
                    ]"
                >
                    <a-input
                        v-model:value="innerElement.name"
                        :placeholder="$t('text.menu-name')"
                        @change="updateFieldValue($event, 'name')"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('text.menu-title')"
                    :name="[...innerName, 'title']"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', {
                                a: $t('text.menu-title'),
                            }),
                        },
                    ]"
                >
                    <a-input
                        v-model:value="innerElement.title"
                        :placeholder="$t('text.menu-title')"
                        @change="updateFieldValue($event, 'title')"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('text.menu-type')"
                    :name="[...innerName, 'type']"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', {
                                a: $t('text.menu-type'),
                            }),
                        },
                    ]"
                >
                    <a-select
                        v-model:value="innerElement.type"
                        :options="
                            urlType.map((t) => {
                                return {
                                    value: t.value,
                                    label: t.text.map((txt) => $t(txt)).join(' '),
                                    disabled: !rule(t.role),
                                }
                            })
                        "
                        :placeholder="$t('text.select')"
                        @change="updateFieldValue($event, 'type')"
                    />
                </a-form-item>

                <template v-if="innerElement.type === 'category'">
                    <input-category
                        v-model="innerElement"
                        :name="[...innerName, 'custom', 'categoryId']"
                        :show-create="false"
                        @change="updateFieldValue($event, 'custom', 'categoryId')"
                        @change-last="onChangeCategory"
                    />

                    <a-form-item
                        v-if="innerElement.type === 'category'"
                        :label="$t('text.menu-expand-child')"
                    >
                        <a-switch
                            v-model:checked="innerElement.custom.isExpandChild"
                            data-testid="menu-expand-child"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                            @change="updateFieldValue($event, 'custom', 'isExpandChild')"
                        />
                    </a-form-item>

                    <a-form-item
                        v-if="innerElement.custom.isExpandChild"
                        :label="$t('text.menu-expand-all-child')"
                    >
                        <a-switch
                            data-testid="menu-expand-all-child"
                            :checked="isExpandAllChild"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                            @change="setExpandAllChild"
                        />
                    </a-form-item>

                    <a-form-item
                        v-if="innerElement.custom.isExpandChild && !isExpandAllChild"
                        :label="$t('text.menu-expand-child-level')"
                        :name="[...innerName, 'custom', 'expandChildLevel']"
                        :rules="[
                            {
                                required: true,
                                type: 'number',
                                min: 1,
                                trigger: 'change',
                                message: $t('validation.min.numeric', {
                                    attribute: $t('text.menu-expand-child-level'),
                                    min: 1,
                                }),
                            },
                        ]"
                    >
                        <a-input-number
                            v-model:value="innerElement.custom.expandChildLevel"
                            data-testid="menu-expand-child-level"
                            :min="1"
                            :precision="0"
                            :step="1"
                            @change="updateFieldValue($event, 'custom', 'expandChildLevel')"
                        />
                    </a-form-item>
                </template>
                <template v-if="innerElement.type === 'post'">
                    <select-input-post
                        v-model="innerElement.target_id"
                        v-model:slug="innerElement.slug"
                        name="target_id"
                        :label="`${$t('text.select')} ${$t('nav.a.posts')}`"
                        @change="updateFieldValue($event, 'target_id')"
                        @update:slug="setUrl"
                    />
                </template>

                <a-form-item :label="$t('text.menu-url')">
                    <a-input
                        v-model:value="innerElement.url"
                        :placeholder="$t('text.menu-url')"
                        type="text"
                        @change="updateFieldValue($event, 'url')"
                    />
                </a-form-item>

                <a-form-item :label="$t('text.ui.menu-item.url_prefix', { url: $t('text.menu-url') })">
                    <a-input
                        v-model:value="innerElement.custom.prefix"
                        :placeholder="$t('text.ui.menu-item.prefix_placeholder')"
                        type="text"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('text.menu-click-action')"
                    :name="[...innerName, 'custom', 'action']"
                >
                    <a-radio-group v-model:value="innerElement.custom.action">
                        <a-radio value="_self">
                            {{ $t('enum.click_self') }}
                        </a-radio>
                        <a-radio value="_blank">
                            {{ $t('enum.click_blank') }}
                        </a-radio>
                    </a-radio-group>
                </a-form-item>

                <div class="grid gap-x-3 grid-cols-2 pick-color">
                    <a-form-item
                        :label="$t('text.menu-icon')"
                        :name="[...innerName, 'custom', 'icon']"
                    >
                        <a-input
                            v-model:value="innerElement.custom.icon"
                            :placeholder="$t('text.menu-icon')"
                        />
                    </a-form-item>

                    <a-form-item
                        :label="`${$t('text.menu-color')} (HEX)`"
                        :name="[...innerName, 'custom', 'color']"
                        class="color-picker"
                    >
                        <a-input
                            v-model:value="innerElement.custom.color"
                            :placeholder="$t('text.menu-color')"
                        />
                    </a-form-item>
                </div>

                <div class="flex items-center text-sm space-x-3 pb-1.5 pt-2">
                    <a-popconfirm
                        :cancel-text="$t('nav.b.cancel')"
                        :ok-text="$t('nav.b.delete')"
                        :title="$t('text.del-confirm')"
                        ok-type="danger"
                        @confirm="deleteElement"
                    >
                        <span class="cursor-pointer text-rose-500">
                            <i-icon class="size-4" icon="delete" />
                            {{ $t('nav.b.delete') }}
                        </span>
                    </a-popconfirm>

                    <span
                        class="text-gray-500 hover:text-gray-800 cursor-pointer flex items-center flex-1 justify-end"
                        role="button"
                        @click="innerElement.isShow = !innerElement.isShow"
                    >
                        <span class="mr-1">{{ $t('nav.b.hide') }}</span>
                        <svg
                            class="h-4 w-4"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                clip-rule="evenodd"
                                d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z"
                                fill-rule="evenodd"
                            />
                        </svg>
                    </span>
                </div>
            </div>
        </transition>

        <draggable
            v-model="innerElement.items"
            :class="[
                'pl-3 py-2 border-l border-b border-dashed space-y-2 transition-all',
                dragState ? 'bg-gray-50 py-6' : '',
            ]"
            :group="{ name: 'g1' }"
            item-key="id"
            tag="div"
            @dragenter="enterState = true"
            @dragleave="enterState = false"
            @dragover="enterState = true"
            @end="dragState = false"
            @start="dragState = true"
        >
            <template #item="{ element, index }">
                <menu-item-v2
                    :element="element"
                    :index="index"
                    :name="[...innerName, 'items', index]"
                    @change="updateElement"
                    @duplicate="duplicateChildItem"
                    @delete-element="(index, id) => deleteNestedItem(index)"
                    @request-delete="deleteItem"
                />
            </template>
        </draggable>
    </div>
</template>
<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { rule, ucfirst } from '@/mixins'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'

const enterState = ref(false)
const props = defineProps({
    element: {
        type: Object,
        default: () => {},
    },
    index: { type: Number, default: 0 },
    name: { type: Array, default: '' },
})
const emit = defineEmits([
    'target-change',
    'update:element',
    'delete-element',
    'change',
    'duplicate',
    'request-delete',
])

defineComponent({
    name: 'MenuItemV2',
})

const { t } = useI18n()

const duplicateElement = () => {
    emit('duplicate', { ...innerElement.value, id: 0 })
}

const duplicateChildItem = (element) => {
    const newItem = { ...element, id: 0, name: t('text.ui.menu-item.copy_name', { name: element.name }) }

    innerElement.value.items.push(newItem)

    updateElement()
}

const innerElement = ref(props.element || {})
const innerName = ref(props.name || [])
const dragState = ref(false)
const defaultExpandChildLevel = 2
const normalizeExpandChildLevel = (value) => {
    const level = Number(value)

    return Number.isInteger(level) && level >= 0 ? level : defaultExpandChildLevel
}
const normalizeCategoryExpansion = () => {
    innerElement.value.custom = {
        ...(innerElement.value.custom || {}),
        expandChildLevel: normalizeExpandChildLevel(
            innerElement.value.custom?.expandChildLevel
        ),
    }
}
normalizeCategoryExpansion()
const lastLimitedExpandChildLevel = ref(
    innerElement.value.custom.expandChildLevel > 0
        ? innerElement.value.custom.expandChildLevel
        : defaultExpandChildLevel
)
const isExpandAllChild = computed(
    () => innerElement.value.custom.expandChildLevel === 0
)
const urlType = [
    { value: 'category', text: ['nav.a.categories'], role: 'category.viewAny' },
    { value: 'gallery', text: ['nav.a.galleries'], role: 'gallery.viewAny' },
    { value: 'place', text: ['nav.a.places'], role: 'place.viewAny' },
    { value: 'post', text: ['nav.a.posts', 'text.root'], role: 'post.viewAny' },
    { value: 'product', text: ['nav.a.products'], role: 'product.viewAny' },
    { value: 'url', text: ['text.custom-url'], role: '' },
]
//  Change categories and target
const onChangeCategory = (val) => {
    innerElement.value.target_id = val.id || val.node || 0
    if (innerElement.value.type !== 'url') {
        innerElement.value.target_type = `App\\Models\\${ucfirst(innerElement.value.type)}`
        innerElement.value.url = val.slug || ''
        innerElement.value.custom.slugs = val.slugs || {}
        if (innerElement.value.name === '') {
            innerElement.value.name = val.name
        }
        if (innerElement.value.title === '') {
            innerElement.value.title = val.name
        }
    } else {
        innerElement.value.target_type = ''
        innerElement.value.url = ''
        innerElement.value.custom.slugs = {}
    }

    updateElement()
}
//  Set url from slugs of target by selection
const setUrl = (val) => {
    innerElement.value.url = val
}

const setExpandAllChild = (enabled) => {
    if (enabled) {
        if (innerElement.value.custom.expandChildLevel > 0) {
            lastLimitedExpandChildLevel.value = innerElement.value.custom.expandChildLevel
        }
        innerElement.value.custom.expandChildLevel = 0
    } else {
        innerElement.value.custom.expandChildLevel = Math.max(
            1,
            lastLimitedExpandChildLevel.value || defaultExpandChildLevel
        )
    }

    updateElement()
}

const deleteNestedItem = (index) => {
    innerElement.value.items.splice(index, 1)
    updateElement()
}

//  Update field value by name
const updateFieldValue = ($event = null, name = '', name_child = '') => {
    if (!name || !$event) {
        return
    }

    //  Get event value
    const value = $event.target ? $event.target.value : $event

    if (name_child) {
        innerElement.value[name][name_child] = value
    } else {
        innerElement.value[name] = value
    }
    //  Update element
    updateElement()
}

//  Fire to update element
const updateElement = () => {
    emit('update:element', innerElement.value)
    emit('change', innerElement.value)
}
//  Delete this element
const deleteElement = () => {
    if (innerElement.value.id > 0) {
        emit('request-delete', { index: props.index, id: innerElement.value.id })
    } else {
        emit('delete-element', props.index, innerElement.value.id)
    }
}
//  Remove item by index
const deleteItem = async ({ index, id: itemId }) => {
    if (itemId > 0) {
        emit('request-delete', { index, id: itemId })
    } else {
        innerElement.value.items.splice(index, 1)
        updateElement()
    }
}

watch(
    () => props.element,
    (value) => {
        if (value !== innerElement.value) {
            innerElement.value = value
            normalizeCategoryExpansion()
            lastLimitedExpandChildLevel.value =
                innerElement.value.custom.expandChildLevel > 0
                    ? innerElement.value.custom.expandChildLevel
                    : defaultExpandChildLevel
        }
    }
)

watch(
    () => innerElement.value.type,
    (val) => {
        const custom = {
            ...innerElement.value.custom,
            prefix: '',
            categoryId: null, // Reset category ID
            isExpandChild: false,
            expandChildLevel: defaultExpandChildLevel,
        }
        innerElement.value.target_id = 0
        innerElement.value.slug = ''
        innerElement.value.url = ''
        innerElement.value.custom = custom

        // If switching from post to category or other types, reset values
        if (val !== 'category') {
            innerElement.value.custom.categoryId = null
            innerElement.value.custom.isExpandChild = false
        }

        if (val === 'url') {
            innerElement.value.target_type = ''
        } else {
            innerElement.value.target_type = `App\\Models\\${ucfirst(val)}`
        }

        updateElement()
    }
)
</script>
