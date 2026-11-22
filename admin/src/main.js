/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { createApp, defineAsyncComponent } from 'vue'

import '@/css/app.css'
/* these are necessary styles for vue flow */
import '@vue-flow/core/dist/style.css'
/* this contains the default theme, these are optional styles */
import '@vue-flow/core/dist/theme-default.css'

import App from './App.vue'
import AntDesignVue from 'ant-design-vue'
import iSPAElement from 'ispa-element'
import { router } from '@/config/route'
import { i18n } from '@/config/i18n'
import { setAlertTitleTranslator } from '@/mixins/utils'
import VueGoogleMaps from '@/components/maps/main'
import VueApexCharts from 'vue3-apexcharts'
import isMobilePlugin from '@/utils/isMobile.js'
import { VueQueryPlugin } from '@tanstack/vue-query'
/** Components import */
// import NavSecond from '@/components/nav-second.vue'
import IContents from '@/components/i-contents.vue'
import IUpload from '@/components/i-upload.vue'
import IImage from '@/components/i-image.vue'
import ISeo from '@/components/i-seo.vue'
import IIcon from '@/components/i-icon.vue'
import IFormConfig from '@/components/i-form-config.vue'
import MenuItemV2 from '@/components/menu-item-v2.vue'
import FieldImage from '@/components/fields/field-image.vue'
import FieldInput from '@/components/fields/field-input.vue'
import FieldLogic from '@/components/field-logic.vue'
import FieldSelect from '@/components/fields/field-select.vue'
import FieldBoolean from '@/components/fields/field-boolean.vue'
import FieldText from '@/components/fields/field-text.vue'
import FolderNode from '@/components/folder-node.vue'
import QuickReport from '@/components/quick-report.vue'
import IStatTile from '@/components/common/i-stat-tile.vue'
import IStatStrip from '@/components/common/i-stat-strip.vue'
import IChart from '@/components/common/i-chart.vue'
import IAttentionStrip from '@/components/common/i-attention-strip.vue'
import IBox from '@/components/common/i-box.vue'
import IConfigIcons from '@/components/i-config-icons.vue'
import NoPermissionError from '@/components/no-permission-error.vue'
import Filter from '@/components/filter.vue'
import MediaCenter from '@/components/media-center.vue'
import ModalMediaCenter from '@/components/modal-media-center.vue'
import ModalEditContent from '@/components/modal-edit-content.vue'
import SlugV3 from '@/components/slug-v3.vue'
import InputCategory from '@/components/input-category.vue'
import InputPublishedDate from '@/components/input-published-date.vue'
import ImageV3 from '@/components/image-v3.vue'
import DroppableMap from '@/components/maps/droppable-map.vue'
import MediaSelection from '@/components/media-selection.vue'
import MetaAttributeInput from '@/components/meta-attribute-input.vue'
import MetaAttributesForm from '@/components/meta-attributes-form.vue'
import SelectInputTag from '@/components/select-input-tag.vue'
import SelectInputComponents from '@/components/select-input-components.vue'
import SelectInputContact from '@/components/select-input-contact.vue'
import SelectInputEnumerateType from '@/components/select-input-enumerate-type.vue'
import SelectInputLanguage from '@/components/select-input-language.vue'
import SelectInputMetaAttribute from '@/components/select-input-meta-attribute.vue'
import SelectInputPost from '@/components/select-input-post.vue'
import SelectInputProject from '@/components/select-input-project.vue'
import SelectInputProvince from '@/components/select-input-province.vue'
import SelectInputStorage from '@/components/select-input-storage.vue'
import SelectInputUser from '@/components/select-input-user.vue'
import SelectInputUserRole from '@/components/select-input-user-role.vue'
import SelectInputWidget from '@/components/select-input-widget.vue'
import SeoBox from '@/components/seo-box.vue'
import SeoUrlParser from '@/components/seo-url-parser.vue'
import ViewDetailContent from '@/components/view-detail-content.vue'
import SubTitle from '@/components/sub-title.vue'
import SelectInputTranslate from '@/components/select-input-translate.vue'
import InputTag from '@/components/input-tag.vue'
import InputExcerpt from '@/cms/core/components/input-excerpt.vue'
import AppIconAction from '@/components/common/AppIconAction.vue'
import AppStatPill from '@/components/common/AppStatPill.vue'
import SelectInputStatus from '@/components/select-input-status.vue'

const CategoryManage = defineAsyncComponent(() => import('@/pages/category/manage.vue'))
const TagManage = defineAsyncComponent(() => import('@/pages/category/tag.vue'))

// Shared notification titles (Success / Error / ...) follow the admin language.
setAlertTitleTranslator((key) => (i18n.global.te(key) ? i18n.global.t(key) : null))

const app = createApp(App)

app.use(iSPAElement)
    .use(AntDesignVue)
    .use(VueQueryPlugin, {
        queryClientConfig: {
            defaultOptions: {
                queries: {
                    refetchOnWindowFocus: false,
                },
            },
        },
    })
    .use(router)
    .use(i18n)
    .use(VueGoogleMaps, {
        load: {
            key: import.meta.env.VITE_GG_TOKEN || null,
            libraries: 'places',
        },
    })
    .use(VueApexCharts)
    .use(isMobilePlugin)

// app.component('NavSecond', NavSecond)
app.component('IContents', IContents)
app.component('IUpload', IUpload)
app.component('IImage', IImage)
app.component('ISeo', ISeo)
app.component('IFormConfig', IFormConfig)
app.component('MenuItemV2', MenuItemV2)
app.component('FieldImage', FieldImage)
app.component('FieldInput', FieldInput)
app.component('FieldLogic', FieldLogic)
app.component('FieldSelect', FieldSelect)
app.component('FieldText', FieldText)
app.component('FieldBoolean', FieldBoolean)
app.component('FolderNode', FolderNode)
app.component('QuickReport', QuickReport)
app.component('IConfigIcons', IConfigIcons)
app.component('i-icon', IIcon)
app.component('i-stat-tile', IStatTile)
app.component('i-stat-strip', IStatStrip)
app.component('i-chart', IChart)
app.component('i-attention-strip', IAttentionStrip)
app.component('i-box', IBox)
app.component('NoPermissionError', NoPermissionError)
app.component('i-filter', Filter)
app.component('MediaCenter', MediaCenter)
app.component('ModalMediaCenter', ModalMediaCenter)
app.component('ModalEditContent', ModalEditContent)
app.component('SlugV3', SlugV3)
app.component('media-selection', MediaSelection)
app.component('InputCategory', InputCategory)
app.component('input-published-date', InputPublishedDate)
app.component('input-excerpt', InputExcerpt)
app.component('InputTag', InputTag)
app.component('ImageV3', ImageV3)
app.component('DroppableMap', DroppableMap)
app.component('MetaAttributeInput', MetaAttributeInput)
app.component('MetaAttributesForm', MetaAttributesForm)
app.component('SelectInputComponents', SelectInputComponents)
app.component('SelectInputContact', SelectInputContact)
app.component('SelectInputEnumerateType', SelectInputEnumerateType)
app.component('SelectInputLanguage', SelectInputLanguage)
app.component('SelectInputMetaAttribute', SelectInputMetaAttribute)
app.component('SelectInputPost', SelectInputPost)
app.component('SelectInputStorage', SelectInputStorage)
app.component('SelectInputProject', SelectInputProject)
app.component('SelectInputProvince', SelectInputProvince)
app.component('SelectInputStatus', SelectInputStatus)
app.component('SelectInputTag', SelectInputTag)
app.component('SelectInputTranslate', SelectInputTranslate)
app.component('SelectInputUser', SelectInputUser)
app.component('SelectInputUserRole', SelectInputUserRole)
app.component('SelectInputWidget', SelectInputWidget)
app.component('seo-box', SeoBox)
app.component('seo-url-parser', SeoUrlParser)
app.component('ViewDetailContent', ViewDetailContent)
app.component('SubTitle', SubTitle)
app.component('CategoryManage', CategoryManage)
app.component('TagManage', TagManage)
app.component('AppIconAction', AppIconAction)
app.component('AppStatPill', AppStatPill)

defineAsyncComponent(app.mount('#app'))
