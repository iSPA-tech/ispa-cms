<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!-- Moderation actions of one comment. Shared by the table action column and the stacked phone row. -->
<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <template v-if="!comment.isDeleted">
            <template v-if="canUpdate">
                <!-- A comment that still holds banned words is approved only after an explicit confirm. -->
                <a-popconfirm
                    v-if="canApprove && bannedWords.length"
                    :cancel-text="$t('nav.b.cancel')"
                    :ok-text="$t('nav.b.confirm')"
                    :title="$t('text.comment.banned_confirm', { words: bannedWords.join(', ') })"
                    overlay-class-name="max-w-xs"
                    @confirm="emit('approve', comment)"
                >
                    <i-button
                        :disabled="busy"
                        data-testid="comment-approve"
                        size="xs"
                        variant="primary"
                    >
                        <template #icon>
                            <i-icon class="size-3.5" icon="check-circle-outline" />
                        </template>
                        <span>{{ $t('nav.b.confirm') }}</span>
                    </i-button>
                </a-popconfirm>
                <i-button
                    v-else-if="canApprove"
                    :disabled="busy"
                    data-testid="comment-approve"
                    size="xs"
                    variant="primary"
                    @click="emit('approve', comment)"
                >
                    <template #icon>
                        <i-icon class="size-3.5" icon="check-circle-outline" />
                    </template>
                    <span>{{ $t('nav.b.confirm') }}</span>
                </i-button>
                <i-button
                    v-if="canReject"
                    :disabled="busy"
                    data-testid="comment-reject"
                    size="xs"
                    @click="emit('reject', comment)"
                >
                    <template #icon>
                        <i-icon class="size-3.5" icon="close" />
                    </template>
                    <span>{{ $t('nav.b.reject') }}</span>
                </i-button>
            </template>
            <a-tooltip v-if="showView" :title="$t('nav.b.view')">
                <i-button
                    :aria-label="$t('nav.b.view')"
                    data-testid="comment-view"
                    size="xs"
                    variant="ghost"
                    @click="emit('view', comment)"
                >
                    <i-icon class="size-4" icon="eye" />
                </i-button>
            </a-tooltip>
            <a-popconfirm
                v-if="canDelete"
                :cancel-text="$t('nav.b.cancel')"
                :ok-text="$t('nav.b.delete')"
                :title="$t('text.del-confirm')"
                ok-type="danger"
                @confirm="emit('delete', comment)"
            >
                <a-tooltip :title="$t('nav.b.delete')">
                    <i-button
                        :aria-label="$t('nav.b.delete')"
                        :disabled="busy"
                        class="text-danger-ink!"
                        data-testid="comment-delete"
                        size="xs"
                        variant="ghost"
                    >
                        <i-icon class="size-4" icon="delete" />
                    </i-button>
                </a-tooltip>
            </a-popconfirm>
        </template>
        <i-button
            v-else-if="canDelete"
            :disabled="busy"
            data-testid="comment-restore"
            size="xs"
            @click="emit('restore', comment)"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="restore" />
            </template>
            <span>{{ $t('nav.b.restore') }}</span>
        </i-button>
    </div>
</template>

<script setup>
import { computed, defineComponent } from 'vue'
import { bannedWordsOf } from '../comment-banned-words'

defineComponent({
    name: 'CommentRowActions',
})

const props = defineProps({
    comment: {
        type: Object,
        default: () => ({}),
    },
    busy: {
        type: Boolean,
        default: false,
    },
    canUpdate: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    showView: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['approve', 'reject', 'view', 'delete', 'restore'])

// Approve is offered until the comment is published; reject until it is rejected.
const canApprove = computed(() => !['status_published', 'confirmed'].includes(props.comment?.status))
const bannedWords = computed(() => bannedWordsOf(props.comment))
const canReject = computed(() => props.comment?.status !== 'rejected')
</script>
