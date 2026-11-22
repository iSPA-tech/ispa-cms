/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { i18n } from '@/config/i18n'
import { iAlert } from '@/mixins'

/**
 * Human toast for the module's normalized API errors: the server's frozen
 * error envelope speaks English codes/messages, so every catch used to dump
 * that raw text at the admin. Codes map to the widget_site_layout.server_error.*
 * pack entries (Vietnamese-first, action-oriented); anything unmapped or
 * untranslated falls back to the server's own message so no failure is ever
 * swallowed silently.
 */
const CODE_TO_SUFFIX = {
    WIDGET_PERMISSION_DENIED: 'permission_denied',
    WIDGET_DRAFT_STALE: 'draft_stale',
    WIDGET_USAGE_CHANGED: 'usage_changed',
    WIDGET_INSTANCE_IN_USE: 'instance_in_use',
    WIDGET_DEFINITION_IN_USE: 'instance_in_use',
    WIDGET_TEMPLATE_IN_USE: 'instance_in_use',
    WIDGET_MANIFEST_SOURCE_INVALID: 'manifest_source_invalid',
    WIDGET_MANIFEST_APPROVAL_REQUIRED: 'manifest_approval_required',
    WIDGET_MANIFEST_REVOKED: 'manifest_revoked',
    WIDGET_MANIFEST_RUNTIME_BLOCKED: 'manifest_runtime_blocked',
    WIDGET_MANIFEST_MATERIALIZATION_FAILED: 'manifest_materialization_failed',
    WIDGET_MANIFEST_NOT_FOUND: 'manifest_not_found',
    WIDGET_ATTESTATION_VERSION_STALE: 'attestation_version_stale',
    WIDGET_RUNTIME_CONTROL_VERSION_STALE: 'runtime_control_version_stale',
    WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE: 'runtime_control_topology_unavailable',
    WIDGET_PREVIEW_SESSION_EXPIRED: 'preview_session_expired',
    WIDGET_IDEMPOTENCY_IN_PROGRESS: 'idempotency_in_progress',
    WIDGET_IDEMPOTENCY_MISMATCH: 'idempotency_mismatch',
    WIDGET_PROVIDER_UNAVAILABLE: 'provider_unavailable',
    WIDGET_PROVIDER_INVALID: 'provider_unavailable',
    WIDGET_REGISTRY_COMPATIBILITY_DRIFT: 'registry_compatibility_drift',
    WIDGET_ARTIFACT_INTEGRITY_FAILED: 'artifact_integrity_failed',
}

export function iWidgetError(error) {
    const suffix = CODE_TO_SUFFIX[error?.code]

    if (suffix) {
        const key = `widget_site_layout.server_error.${suffix}`
        const translated = i18n.global.t(key)
        if (translated !== key) {
            iAlert(translated, true)
            return
        }
    }

    iAlert(error, true)
}
