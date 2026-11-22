<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
	<div v-if="rule('meta_attribute.viewAny')" class="py-4 mb-12">
		<div class="flex flex-col md:flex-row md:items-center mb-6 md:mb-4">
			<h2 class="text-xl font-bold flex-1">
				{{ $t('nav.a.meta_attributes') }}
			</h2>
			<div class="space-x-2">
				<a-button :icon="h(SyncOutlined)" :loading="loadingState" @click="getAttributes">
					{{ $t('nav.b.reload') }}
				</a-button>
				<a-button :icon="h(PlusOutlined)" class="btn-success plain" @click="openDialog">
					{{ $t('nav.b.create') }} {{ $t('nav.a.meta_attributes') }}
				</a-button>
			</div>
		</div>

		<i-filter :allowedFields="[]" module="metaAttribute"></i-filter>

			<div class="pt-6">
				<a-table
					:columns="columns"
					:data-source="dataRef?.data || []"
					:loading="loadingState"
					:pagination="{
                    pageSize: dataRef?.perPage || 10,
                    current: dataRef?.currentPage || 1,
                    total: dataRef?.total || 0,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    showTotal: (total, range) => {
                        return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                            'pagination.of'
                        )} ${total} ${$t('pagination.results')}`
                    },
                }"
					class="w-full"
					row-key="id"
					size="small"
					@change="tableChange"
				>
					<template #headerCell="{ column }">
						<template v-if="Array.isArray(column.title)">
							{{ column.title.map((t) => $t(t)).join(' ') }}
						</template>
					</template>
					<template #bodyCell="{ column: col, record: row }">
						<template v-if="col.key === 'name'">
							<span>{{ row.name }}</span>
							<span v-if="row.is_required" class="text-rose-600 font-bold pl-1">*</span>
						</template>
						<template v-if="col.key === 'label'">{{ row.translated_label }}</template>
						<template v-if="col.key === 'type'">{{ row.type || '' }}</template>
						<template v-if="col.key === 'input_type'">{{ row.input_type || '' }}</template>
						<template v-if="col.key === 'value'">{{ row.total_values }}</template>
						<template v-if="col.key === 'status'">
							<div class="space-x-2">
								<a-tooltip :title="$t('validation.attributes.is_enabled')">
                                <span
	                                v-if="row.is_enabled"
	                                :class="[row.is_enabled ? 'text-green-600' : 'text-gray-500']"
                                >
                                    <i-icon class="size-4" icon="eye"/>
                                </span>
								</a-tooltip>
								<a-tooltip :title="$t('validation.attributes.is_filter')">
                                <span v-if="row.is_filter" class="text-orange-600">
                                    <i-icon icon="filter" class="size-4"/>
                                </span>
								</a-tooltip>
								<a-tooltip :title="$t('text.multiple')">
                                <span v-if="row.is_multiple" class="text-cyan-600">
                                    <i-icon icon="content-copy" class="size-4"/>
                                </span>
								</a-tooltip>
							</div>
						</template>
						<template v-if="col.key === 'action'">
							<a-tooltip v-if="rule('meta_attribute.edit')" :title="$t('nav.b.edit')">
								<a-button
									:icon="h(EditOutlined)"
									class="btn-info text"
									type="text"
									@click="editAttribute(row.id)"
								/>
							</a-tooltip>
							<a-tooltip
								v-if="rule('meta_attribute.edit')"
								:title="$t('nav.a.meta_values')"
							>
								<a-button
									:icon="h(GroupOutlined)"
									class="btn-success text"
									type="text"
									@click="valueAttribute(row.id)"
								/>
							</a-tooltip>
						</template>
					</template>
				</a-table>
			</div>

			<a-modal
				v-model:open="modalState"
				:title="`${$t(form.id > 0 ? 'nav.b.update' : 'nav.b.create')} ${$t('nav.a.meta_attributes')}`"
				:width="$isMobile() ? '100%' : '50%'"
				@close="closeDialog"
			>
				<a-form
					id="metaAttributeForm"
					:model="form"
					:rules="formRule"
					layout="vertical"
					@finish="submitForm"
					@finish-failed="finishFailed"
				>
					<div class="grid gap-x-3 grid-cols-2 pick-color">
						<a-form-item :label="$t('validation.attributes.type')" class="w-full" name="type">
							<a-select
								v-model:value="form.type"
								:placeholder="$t('text.select')"
								@change="typeChange"
							>
								<a-select-option
									v-for="(tab, ind) in valueType"
									:key="ind"
									:label="$t(`enum.attr.${ind}`)"
									:value="ind"
								></a-select-option>
							</a-select>
						</a-form-item>

						<a-form-item :label="$t('validation.attributes.input_type')" class="w-full" name="input_type">
							<a-select v-model:value="form.input_type" :placeholder="$t('text.select')">
								<a-select-option
									v-for="(tab, ind) in valueType[form.type]"
									:key="ind"
									:label="$t(`enum.${tab}`)"
									:value="tab"
								></a-select-option>
							</a-select>
						</a-form-item>

						<a-form-item
							:label="`${$t('validation.attributes.name')} ${$t('nav.a.meta_attributes')}`"
							name="name"
						>
							<a-input
								v-model:value="form.name"
								:placeholder="`${$t('validation.attributes.name')} ${$t('nav.a.meta_attributes')}`"
							/>
						</a-form-item>

						<select-input-translate
							v-model="form.label"
							:label="`${$t('text.label')} ${$t('nav.a.meta_attributes')}`"
							name="label"
						/>

						<select-input-translate
							v-model="form.description"
							:label="`${$t('validation.attributes.description')} ${$t('nav.a.meta_attributes')}`"
							name="description"
						/>

						<a-form-item :label="$t('text.required')" name="is_required">
							<a-switch
								v-model:checked="form.is_required"
								:checked-children="$t('text.turn-on')"
								:un-checked-children="$t('text.turn-off')"
							/>
						</a-form-item>

						<a-form-item :label="$t('validation.attributes.is_enabled')" name="is_enabled">
							<a-switch
								v-model:checked="form.is_enabled"
								:checked-children="$t('text.turn-on')"
								:un-checked-children="$t('text.turn-off')"
							/>
						</a-form-item>

						<a-form-item :label="$t('validation.attributes.is_filter')" name="is_filter">
							<a-switch
								v-model:checked="form.is_filter"
								:checked-children="$t('text.turn-on')"
								:un-checked-children="$t('text.turn-off')"
							/>
						</a-form-item>

						<a-form-item :label="$t('text.multiple')" name="is_multiple">
							<a-switch
								v-model:checked="form.is_multiple"
								:checked-children="$t('text.turn-on')"
								:un-checked-children="$t('text.turn-off')"
							/>
						</a-form-item>
					</div>
				</a-form>

				<template #footer>
					<a-button
						:disabled="!rule(['meta_class.create', 'meta_class.update'])"
						:icon="h(CloseCircleOutlined)"
						@click="closeDialog"
					>
						{{ $t('nav.b.cancel') }}
					</a-button>
					<a-button
						:disabled="!rule(['meta_class.create', 'meta_class.update'])"
						:icon="h(SaveOutlined)"
						:loading="btnState"
						danger
						form="metaAttributeForm"
						html-type="submit"
						type="primary"
					>
						{{ $t('nav.b.save') }}
					</a-button>
				</template>
			</a-modal>
			<a-modal
				v-model:open="valueState"
				:title="$t('nav.a.meta_values')"
				class="modal-fullscreen"
				width="100%"
			>
			</a-modal>
	</div>
	<no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { defineComponent, h, onMounted, reactive, ref, watch } from 'vue'
import { finishFailed, iAlert, initFilter, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { Form } from 'ant-design-vue'
import {
	CloseCircleOutlined,
	EditOutlined,
	GroupOutlined,
	PlusOutlined,
	SaveOutlined,
	SyncOutlined,
} from '@ant-design/icons-vue'
import {
	getMetaAttribute,
	getMetaAttributes,
	storeMetaAttribute,
	updateMetaAttribute,
} from '@/libs'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { metaAttributeFilter } from '@/config'

const useForm = Form.useForm

defineComponent({
	name: 'SettingMetaAttribute',
})
const {t} = useI18n()
const modalState = ref(false)
const btnState = ref(false)
const loadingState = ref(false)
const route = useRoute()
const router = useRouter()
const dataRef = ref({})
const queryData = reactive(initFilter(metaAttributeFilter, route.query))
const getAttributes = async () => {
	loadingState.value = true
	await getMetaAttributes(queryData)
		.then((res) => {
			if (res?.status === 'success') {
				dataRef.value = res?.data || {}
			}
			if (res?.message) {
				iAlert(res)
			}
		})
		.catch((err) => {
			iAlert({...err, title: t('text.title.error')}, true)
		})
		.finally(() => {
			loadingState.value = false
		})
}
// const { data, refetch, isLoading, isFetching } = useMetaAttributes(queryData, {
//     onSuccess: (data) => {
//         dataRef.value = data?.data || {}
//     },
// })
const tableChange = (pagination) => {
	const newFilterParams = {
		...queryData,
		page: pagination.current,
		pageSize: pagination.pageSize,
	}

	router.push({
		name: 'setting-meta-attribute',
		query: newFilterParams,
	})
}
const columns = [
	{key: 'name', title: ['validation.attributes.name']},
	{key: 'label', title: ['text.label']},
	{key: 'type', title: ['validation.attributes.type']},
	{key: 'input_type', title: ['validation.attributes.input_type']},
	{key: 'value', title: ['text.value']},
	{key: 'status', title: ['validation.attributes.status']},
	{key: 'action', align: 'right'},
]

const valueType = ref({
	string: [
		'text',
		'color',
		'checkbox',
		'select',
		'radio',
		'email',
		'password',
		'url',
		'search',
		'range',
	],
	text: ['textarea', 'image', 'all', 'document', 'archive'],
	bigInt: ['number', 'tel', 'timestamp'],
	tinyInt: ['number', 'week', 'month', 'year', 'day'],
	boolean: ['checkbox', 'switch', 'radio', 'select', 'boolean'],
	date: ['date', 'time', 'datetime'],
})

const formDefault = () => {
	return {
		id: 0,
		name: null,
		type: 'string',
		input_type: 'text',
		label: null,
		description: null,
		is_filter: true,
		is_enabled: true,
		is_required: false,
		options: [],
	}
}
const form = ref(formDefault())
const formRule = ref({})
const {resetFields, clearValidate} = useForm(form, formRule)
const resetForm = () => {
	form.value = formDefault()
	resetFields()
	clearValidate()
}
const submitForm = (data = {}) => {
	const value = objectAssignOnlyByKeyHasValue(form.value, data)

	if (btnState.value) {
	    return
	}
	btnState.value = true
	const call =
		form.value.id > 0 ? updateMetaAttribute(value.id, value) : storeMetaAttribute(value)

	call.then((res) => {
		if (res?.status === 'success') {
			if (value.id < 1) {
				resetForm()
			}
			getAttributes()
		}
		if (res?.message) {
			iAlert({...res, title: t('text.title.success')})
		}
	})
		.catch((err) => {
			iAlert({...err, title: t('text.title.error')}, true)
		})
		.finally(() => {
			btnState.value = false
		})
}

const openDialog = () => {
	modalState.value = true

	resetForm()
}

const typeChange = () => {
	form.value.input_type = valueType.value[form.value.type][0]
}

/** Reset form and close modal */
const closeDialog = () => {
	resetForm()

	modalState.value = false
}

const editAttribute = async (id) => {
	await getMetaAttribute(id, true)
		.then((res) => {
			if (res?.status === 'success') {
				form.value = res.data || {}
			}
			if (res?.message) {
				iAlert(res)
			}
		})
		.catch((err) => {
			iAlert({...err, title: t('text.title.error')}, true)
		})

	if (form.value.id < 1) {
		return
	}

	modalState.value = true
}

const valueState = ref(false)
const attributeOptions = ref([])
const valueAttribute = async (id) => {
	await getMetaAttribute(id)
		.then((res) => {
			if (res?.status === 'success') {
				attributeOptions.value = res?.data || []
				valueState.value = true
			}
			if (res?.message) {
				iAlert(res)
			}
		})
		.catch((err) => {
			iAlert({...err, title: t('text.title.error')}, true)
		})
		.finally(() => {
			valueState.value = true
		})
}

watch(
	() => route.query,
	(val) => {
		if (Object.keys(val).length < 1) {
			Object.assign(queryData, metaAttributeFilter)
		} else {
			Object.assign(queryData, initFilter(metaAttributeFilter, val))
		}
		getAttributes()
	},
)

onMounted(() => {
	getAttributes()
})
</script>
