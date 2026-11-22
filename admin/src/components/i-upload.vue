<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <file-pond
        :id="`${id}-file-${index}`"
        ref="pond"
        :accepted-file-types="acceptedFileType"
        :fileValidateTypeDetectType="fileValidateTypeDetectType"
        :labelIdle="selfPhd"
        :maxFiles="max"
        :server="{ process, revert, restore, load, fetch }"
        allow-multiple
        allowFileMetadata
        allowImageExifOrientation
        allowMultiple
        name="file"
        @processfile="handleProcessSuccess"
        v-on:init="handleFilePondInit"
    />
</template>

<script>
import { defineComponent, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { iAlert } from '@/mixins'
/**
 * Run CMD
 *-------------------
 * @author malayvuong
 * npm i vue-filepond filepond filepond-plugin-file-validate-type
 * filepond-plugin-image-preview filepond-plugin-file-metadata
 * filepond-plugin-image-exif-orientation
 * filepond-plugin-image-size-metadata
 *
 * @last_updated 6.19.6 - 2022-05-03, 10:16 ICT - by malayvuong
 * - add new accept type *
 **/
import vueFilePond from 'vue-filepond'
import axios from 'axios'
// Import plugins
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type'
import FilePondPluginImagePreview from 'filepond-plugin-image-preview'
import FilePondPluginFileMetadata from 'filepond-plugin-file-metadata'
import FilePondPluginImageExifOrientation from 'filepond-plugin-image-exif-orientation'
// import FilePondPluginImageSizeMetadata from 'filepond-plugin-image-size-metadata';

// Import styles
import 'filepond/dist/filepond.min.css'
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css'

// Create FilePond component
const FilePond = vueFilePond(
    FilePondPluginFileValidateType,
    FilePondPluginImagePreview,
    FilePondPluginFileMetadata,
    FilePondPluginImageExifOrientation
    // FilePondPluginImageSizeMetadata
)
const acceptFileTypeList = {
    image: 'image/gif, image/vnd.microsoft.icon, image/jpeg, image/png, image/svg+xml, image/webm, image/webp, image/x-icon',
    audio: 'audio/aac, audio/mpeg, application/ogg, audio/wav, audio/webm, audio/x-flac, audio/flac, video/x-ms-wma',
    video: 'video/x-msvideo, video/mpeg, video/webm, video/mp2t, video/mp4, video/x-ms-wmv',
    document:
        'text/csv, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document, application/pdf, application/vnd.ms-powerpoint, application/vnd.openxmlformats-officedocument.presentationml.presentation, text/plain, application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    zip: 'application/gzip, application/vnd.rar, application/zip, application/x-7z-compressed',
}

// If no index => replace data to files
export default defineComponent({
    name: 'i-upload',
    props: {
        id: { type: String, default: 'input' },
        modelValue: { type: Array, default: () => [] },
        //  Index in this files[index] => change and store
        index: { type: [Number, String], default: null },
        max: { type: [Number, String], default: 1 },
        target: { type: String, default: 'User' },
        type: { type: String, default: 'image' },
        accepted: { type: String, default: 'image' },
        category: { type: String, default: null },
        placeholder: { type: String, default: null },
        targetId: {
            type: Number,
            default: (e) => {
                return e >>> 0
            },
        },
    },
    components: {
        FilePond,
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit }) {
        const pond = ref(null)
        const innerModel = ref(props.modelValue || [])
        const selfPhd = ref('')
        const { t } = useI18n()
        const acceptedFileType = ref(acceptFileTypeList[props.accepted])

        const fileValidateTypeDetectType = (source, type) =>
            new Promise((resolve, reject) => {
                resolve(type)
            })
        const process = (
            fieldName,
            file,
            metadata,
            load,
            error,
            progress,
            abort,
            transfer,
            options
        ) => {
            //  Check max-files with current files count
            if (
                pond.value.getFiles().length + (props.modelValue ? props.modelValue.length : 0) >
                parseInt(props.max)
            ) {
                iAlert(t('alert.warning.maxFileAllowed') + props.max, true)
                pond.value.removeFiles()
                return abort('reach max files')
            }

            const CancelToken = axios.CancelToken
            const source = CancelToken.source()
            // fieldName is the name of the input field
            // for (let i = 0; i < props.max; i++) {
            const formData = new FormData()
            // Append form data and send its to server
            formData.append(fieldName, file)
            formData.append('name', file.name)
            formData.append('target', props.target)
            formData.append('category', props.category)
            if (props.targetId > 0) {
                formData.append('targetId', props.targetId)
            }
            //  Push width and height data
            // if (props.type === 'image' && metadata.size) {
            //     formData.append(
            //         'width',
            //         metadata.size ? metadata.size.width : 0
            //     )
            //     formData.append(
            //         'height',
            //         metadata.size ? metadata.size.height : 0
            //     )
            // }

            // Config ajax progress upload
            const config = {
                onUploadProgress: (e) => {
                    progress(e.lengthComputable, e.loaded, e.total)
                },
                cancelToken: source.token,
            }

            ajax.post(`upload/${props.type}`, formData, config)
                .then((res) => {
                    if (res.data.status === 'success') {
                        if (props.index !== null) {
                            if (!innerModel.value[props.index]) {
                                innerModel.value[props.index] = {}
                            }
                            innerModel.value[props.index] = res.data.data
                            load(props.index)
                        } else {
                            innerModel.value.push(res.data.data)
                            load(innerModel.value.length - 1)
                        }
                        pond.value.removeFile(file)
                        //  Process to update model value
                        nextTick(updateModelValue)
                    } else {
                        iAlert(res.data)
                        error(t('text.media.upload_failed'))
                    }
                })
                .catch((err) => {
                    iAlert(err, true)
                    abort()
                })
            // }

            return {
                abort: () => {
                    source.cancel(t('text.media.upload_canceled'))
                    abort()
                },
            }
        }

        const revert = (uniqueFileId, load, error) => {
            // No need to delete on server
        }
        const load = (uniqueFileId, load, error) => {
            // load()
        }
        const fetch = (url, load, error, progress, abort, headers) => {
            error('')
        }
        const restore = (uniqueFileId, load, error, progress, abort, headers) => {
            error()
        }
        const handleError = (error, e) => {
            switch (e.code) {
                case 'storage/canceled':
                    break
                default:
                    error(e.message)
            }
        }
        const handleProcessSuccess = (error, file) => {
            pond.value.removeFiles()
        }
        const handleFilePondInit = () => {}
        /** Update model value */
        const updateModelValue = () => {
            /** Remove null value */
            if (innerModel.value && innerModel.value.length > 0) {
                innerModel.value.forEach((e, index) => {
                    if (e === null) {
                        innerModel.value.splice(index, 1)
                    }
                })
            }
            emit('update:modelValue', innerModel.value)
            emit('change')
        }

        watch(
            () => props.modelValue,
            (val, old) => {
                if (val !== old) {
                    innerModel.value = props.modelValue
                }
            }
        )

        onMounted(() => {
            //  Set default label
            if (props.placeholder == null) {
                selfPhd.value = t('text.select-file')
            }

            if (props.accepted === '*') {
                Object.keys(acceptFileTypeList).forEach((e) => {
                    acceptedFileType.value += acceptFileTypeList[e]
                })
            }
        })

        return {
            selfPhd,
            acceptFileTypeList,
            pond,
            acceptedFileType,
            fileValidateTypeDetectType,
            process,
            revert,
            load,
            fetch,
            restore,
            handleError,
            handleProcessSuccess,
            handleFilePondInit,
        }
    },
})
</script>
