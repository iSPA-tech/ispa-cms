<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-5xl mx-auto" data-testid="modules-page">
        <div class="flex flex-wrap items-center pb-4 gap-2">
            <a-input
                v-model:value="search"
                data-testid="modules-search"
                allow-clear
                :placeholder="$t('nav.b.search')"
                class="w-full sm:w-72 sm:mr-auto"
            />
            <i-button v-if="webmaster" size="sm" :disabled="installBlocked" data-testid="module-upload-open" @click="openUpload">
                <template #icon><i-icon icon="upload" class="size-4" /></template>
                {{ $t('text.modules.install.action.upload') }}
            </i-button>
            <i-button
                v-if="webmaster"
                size="sm"
                :loading="integrity.checking"
                data-testid="module-integrity-check"
                @click="runIntegrityCheck"
            >
                {{ $t('text.modules.integrity.check_now') }}
            </i-button>
            <a-button
                :icon="h(SyncOutlined)"
                :loading="isRefetching"
                class="btn-warning"
                data-testid="modules-reload"
                @click="reloadAll"
            >
                {{ $t('nav.b.reload') }}
            </a-button>
        </div>

        <!-- Core update: installed and latest iSPA CMS core, preflight, queued upgrade (Webmaster only) -->
        <core-update-card v-if="webmaster" class="mb-4" @finished="refetch" />
        <hub-connect-drawer
            v-if="webmaster"
            ref="hubDrawer"
            :manual="hubConnectManual"
            :open="hubConnectOpen"
            @attention="openHubConnect(false)"
            @close="hubConnectOpen = false"
            @connected="onHubConnected"
        />
        <!-- "Cho phép cài module": the Webmaster switches installs on here; read-only when the server or iSPA Hub decides -->
        <install-switch v-if="webmaster" ref="installSwitch" class="mb-4" @change="installSetting = $event" />

        <a-tabs v-if="webmaster" v-model:activeKey="tab" data-testid="modules-tabs" @change="onTabChange">
            <a-tab-pane key="installed" :tab="$t('text.modules.install.tab.installed')" />
            <a-tab-pane key="available" :tab="$t('text.modules.install.tab.available')" />
            <a-tab-pane key="history" :tab="$t('text.modules.install.tab.history')" />
            <a-tab-pane key="requests" :tab="$t('text.modules.support.requests.tab')" />
            <a-tab-pane key="report" :tab="$t('text.modules.report.tab')" />
        </a-tabs>

        <!-- Integrity report: when the files were last compared with the signed packages -->
        <div v-if="webmaster && tab === 'installed'" class="pb-3 text-xs text-ink-faint" data-testid="module-integrity-status">
            <a-alert
                v-if="integrity.error"
                type="error"
                show-icon
                :message="integrity.error"
                data-testid="module-integrity-error"
            />
            <span v-else-if="integrity.checkedAt">
                {{ $t('text.modules.integrity.checked_at', { time: formatDate(integrity.checkedAt) }) }}
            </span>
            <span v-else-if="integrity.loaded">{{ $t('text.modules.integrity.never') }}</span>
        </div>

        <!-- Installed modules -->
        <a-spin v-show="tab === 'installed' || !webmaster" :spinning="isLoading || isRefetching">
            <a-empty v-if="!moduleList.length" :description="$t('text.no-data')" />
            <a-empty v-else-if="!filteredList.length" :description="$t('text.not-found')" />
            <a-row v-else :gutter="[16, 16]">
                <a-col :span="24" v-for="module in filteredList" :key="module.name">
                    <a-card class="shadow-sm rounded-lg" data-testid="module-row">
                        <div class="flex flex-col gap-4 md:flex-row md:items-center">
                            <div class="flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-semibold text-ink m-0">
                                        {{ module.label }}
                                    </h3>
                                    <span v-if="module.label !== module.name" class="text-xs text-ink-faint">
                                        {{ module.name }}
                                    </span>
                                    <a-tag :color="module.enabled ? 'success' : 'default'">
                                        {{
                                            module.enabled
                                                ? $t('text.modules.active')
                                                : $t('text.modules.inactive')
                                        }}
                                    </a-tag>
                                    <a-tag v-if="!module.switchable">
                                        <LockOutlined />
                                        {{ $t('text.modules.core_locked') }}
                                    </a-tag>
                                    <a-tag
                                        v-if="module.quarantine?.kind === 'off'"
                                        color="error"
                                        class="max-w-full whitespace-normal"
                                        data-testid="module-quarantine-tag"
                                    >
                                        {{ $t('text.modules.quarantine.tag') }}
                                    </a-tag>
                                    <a-tag
                                        v-else-if="module.quarantine?.kind === 'shadow'"
                                        color="processing"
                                        class="max-w-full whitespace-normal"
                                        data-testid="module-quarantine-shadow"
                                    >
                                        {{ $t(module.quarantine.tagKey) }}
                                    </a-tag>
                                    <a-tag v-if="!isCore(module)" :color="module.source === 'runtime' ? 'blue' : 'default'" data-testid="module-source">
                                        {{ $t('text.modules.install.source.' + module.source) }}
                                    </a-tag>
                                    <a-tooltip v-if="module.entitlement" :title="entitlementTooltip(module.entitlement)">
                                        <a-tag
                                            :color="entitlementColor(module.entitlement.state)"
                                            class="max-w-full whitespace-normal"
                                            data-testid="module-entitlement"
                                        >
                                            {{ entitlementLabel(module.entitlement) }}
                                        </a-tag>
                                    </a-tooltip>
                                    <a-tag v-if="updatesUnavailable(module)" data-testid="module-updates-unavailable">
                                        {{ $t('text.modules.install.updates_unavailable') }}
                                    </a-tag>
                                </div>
                                <p v-if="module.description" class="text-sm text-ink-muted m-0">
                                    {{ module.description }}
                                </p>
                                <!-- Core is part of iSPA CMS: its version and updates live on the iSPA CMS card -->
                                <p v-if="isCore(module)" class="m-0 text-sm text-ink-muted" data-testid="module-core-note">
                                    {{ $t('text.modules.core_row_note') }}
                                </p>
                                <div v-if="module.version && !isCore(module)" class="flex flex-wrap items-center gap-3 text-xs text-ink-faint">
                                    <span>
                                        {{ $t('text.modules.version') }}: {{ module.version }}
                                    </span>
                                    <a-tag
                                        v-if="upgradeFor(module.name)"
                                        color="processing"
                                        class="m-0"
                                        data-testid="module-upgrade-available"
                                    >
                                        {{ $t('text.modules.install.upgrade.available', { version: upgradeFor(module.name).version }) }}
                                    </a-tag>
                                </div>
                                <div
                                    v-if="module.requires && module.requires.length"
                                    class="flex flex-wrap gap-1 text-xs"
                                >
                                    <span class="text-ink-muted"
                                        >{{ $t('text.modules.depends_on') }}:</span
                                    >
                                    <a-tag
                                        v-for="dep in module.requires"
                                        :key="`${module.name}-${dep}`"
                                        color="blue"
                                    >
                                        {{ labelFor(dep) }}
                                    </a-tag>
                                </div>
                                <a-alert
                                    v-if="module.quarantine"
                                    :type="module.quarantine.kind === 'off' ? 'warning' : 'info'"
                                    show-icon
                                    class="mt-2"
                                    data-testid="module-quarantine-note"
                                    :message="$t(module.quarantine.messageKey)"
                                    :description="$t(module.quarantine.actionKey)"
                                />
                                <a-alert
                                    v-if="module.revoked"
                                    type="warning"
                                    show-icon
                                    class="mt-2"
                                    data-testid="module-revoked"
                                    :message="$t('text.modules.install.revoked_installed')"
                                    :description="module.revoked_reason || undefined"
                                />
                                <a-alert
                                    v-if="entitlementNote(module)"
                                    :type="module.entitlement.state === 'grace' ? 'info' : 'warning'"
                                    show-icon
                                    class="mt-2"
                                    data-testid="module-entitlement-note"
                                    :message="entitlementNote(module)"
                                    :description="module.entitlement.state === 'grace' ? undefined : $t('text.modules.entitlement.keeps_running')"
                                />
                                <integrity-note
                                    v-if="webmaster"
                                    :entry="integrity.modules[module.name] || null"
                                    :source="module.source || ''"
                                />
                            </div>
                            <div v-if="webmaster" class="flex flex-wrap items-center gap-3">
                                <i-button size="sm" data-testid="module-support" @click="openSupport(module)">
                                    {{ $t('text.modules.support.open') }}
                                </i-button>
                                <i-button
                                    v-if="upgradeFor(module.name)"
                                    variant="primary"
                                    size="sm"
                                    :disabled="installBlocked"
                                    data-testid="module-upgrade"
                                    @click="openUpgrade(upgradeFor(module.name))"
                                >
                                    {{ $t('text.modules.install.action.upgrade') }}
                                </i-button>
                                <i-button
                                    v-if="module.source === 'runtime' && module.switchable && !isCore(module)"
                                    variant="danger"
                                    size="sm"
                                    data-testid="module-uninstall"
                                    @click="openUninstall(module)"
                                >
                                    {{ $t('text.modules.install.action.uninstall') }}
                                </i-button>
                                <template v-if="switchMeta(module).reason">
                                    <a-tooltip :title="switchMeta(module).reason">
                                        <a-switch
                                            :checked="module.enabled"
                                            :disabled="true"
                                            size="large"
                                            data-testid="module-switch"
                                        />
                                    </a-tooltip>
                                </template>
                                <template v-else>
                                    <a-switch
                                        :checked="module.enabled"
                                        :disabled="switchMeta(module).disabled"
                                        :loading="Boolean(switching[module.name])"
                                        size="large"
                                        data-testid="module-switch"
                                        @change="(checked) => toggleModule(module, checked)"
                                    />
                                </template>
                            </div>
                        </div>
                    </a-card>
                </a-col>
            </a-row>
        </a-spin>

        <!-- Modules that can be installed -->
        <section v-if="webmaster" v-show="tab === 'available'" class="space-y-3" data-testid="modules-available">
            <!-- Installs are blocked for the whole website: one plain sentence instead of an error list in every drawer -->
            <div
                v-if="installBlocked"
                role="status"
                class="i-box flex flex-wrap items-center justify-between gap-2"
                data-testid="install-blocked-notice"
            >
                <p class="m-0 min-w-0 flex-1 basis-56 break-words text-sm">{{ $t(installBlockedKey) }}</p>
                <i-button v-if="installSetting.state === 'disabled'" size="sm" data-testid="install-blocked-go" @click="revealInstallSwitch">
                    {{ $t('text.modules.install.blocked.go_to_switch') }}
                </i-button>
            </div>
            <div v-if="hubConfigured === false" class="flex flex-wrap items-center gap-3">
                <p class="m-0 text-sm text-ink-muted" data-testid="hub-not-connected">
                    {{ $t('text.modules.install.hub_not_connected') }}
                </p>
                <!-- One tap: the browser goes straight to ispa.tech; the drawer opens only when something needs reading. -->
                <a-button :loading="hubDrawer?.starting" type="primary" data-testid="hub-connect-open" @click="hubDrawer?.start()">
                    {{ $t('text.site_connect.title') }}
                </a-button>
                <a-button :disabled="hubDrawer?.starting" class="px-0" type="link" data-testid="hub-connect-manual-open" @click="openHubConnect(true)">
                    {{ $t('text.hub_connect.manual_link') }}
                </a-button>
                <p v-if="hubDrawer?.waiting" class="m-0 w-full text-sm text-ink-muted" data-testid="hub-connect-waiting" role="status">
                    {{ $t('text.hub_connect.waiting') }}
                </p>
            </div>
            <div v-else-if="catalog.loading && !catalog.loaded" aria-busy="true" data-testid="catalog-loading">
                <a-skeleton active :paragraph="{ rows: 4 }" />
            </div>
            <div v-else-if="catalog.error" role="alert" class="i-box space-y-2" data-testid="catalog-error">
                <p class="m-0 text-sm text-danger">{{ catalog.error }}</p>
                <i-button size="sm" data-testid="catalog-retry" @click="loadCatalog">
                    {{ $t('text.modules.install.retry') }}
                </i-button>
            </div>
            <a-empty
                v-else-if="!catalogRows.length"
                :description="$t('text.modules.install.available_empty')"
                data-testid="catalog-empty"
            />
            <a-empty v-else-if="!filteredCatalog.length" :description="$t('text.not-found')" />
            <store-tab
                :rows="filteredCatalog"
                :locale="locale"
                :describe="describeModule"
                :label-for="labelFor"
                :install-blocked="installBlocked"
                @details="openStore"
                @install="openInstall"
                @upgrade="openUpgrade"
            />
        </section>

        <!-- This site's support requests on the platform hub; read again every time the tab opens -->
        <support-requests v-if="webmaster && tab === 'requests'" :locale="locale" :label-for="readableLabel" />

        <!-- What the daily site report sends to iSPA Hub: off, basic or full, with a preview -->
        <report-level v-if="webmaster && tab === 'report'" />

        <!-- Install history -->
        <section v-if="webmaster" v-show="tab === 'history'" class="i-box space-y-3" data-testid="modules-history">
            <div v-if="installs.loading && !installs.loaded" aria-busy="true" data-testid="history-loading">
                <a-skeleton active :paragraph="{ rows: 3 }" />
            </div>
            <div v-else-if="installs.error" role="alert" class="space-y-2" data-testid="history-error">
                <p class="m-0 text-sm text-danger">{{ installs.error }}</p>
                <i-button size="sm" data-testid="history-retry" @click="loadInstalls()">
                    {{ $t('text.modules.install.retry') }}
                </i-button>
            </div>
            <p v-else-if="!installRows.length" class="m-0 text-sm text-ink-muted" data-testid="history-empty">
                {{ $t('text.modules.install.history.empty') }}
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-border text-xs text-ink-muted">
                            <th class="py-2 pr-3 font-medium">{{ $t('text.modules.install.history.module') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.modules.version') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.modules.install.history.status') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.modules.install.history.source') }}</th>
                            <th class="py-2 font-medium">{{ $t('text.modules.install.history.started') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in installRows"
                            :key="row.uuid"
                            class="border-b border-border align-top"
                            data-testid="history-row"
                        >
                            <td class="py-2 pr-3">
                                {{ readableLabel(row.name) }}
                                <div v-if="row.kind === 'upgrade'" data-testid="history-kind">
                                    <a-tag color="processing" class="m-0">{{ $t('text.modules.install.history.kind_upgrade') }}</a-tag>
                                </div>
                                <div v-else-if="row.kind === 'uninstall'" data-testid="history-kind">
                                    <a-tag color="error" class="m-0">{{ $t('text.modules.install.history.kind_uninstall') }}</a-tag>
                                </div>
                            </td>
                            <td class="py-2 pr-3">
                                <template v-if="row.kind === 'upgrade' && row.previous_version">
                                    <span data-testid="history-upgrade-versions">
                                        {{ $t('text.modules.install.history.upgrade_versions', { from: row.previous_version, to: row.version }) }}
                                    </span>
                                </template>
                                <template v-else>{{ row.version }}</template>
                            </td>
                            <td class="py-2 pr-3">
                                <a-tag :color="installColor(row.status)">{{ installStatusText(row.status) }}</a-tag>
                                <div v-if="waitingForScheduler(row)" class="text-xs text-ink-muted" data-testid="history-waiting-scheduler">
                                    {{ $t('text.modules.install.status.waiting_scheduler') }}
                                </div>
                                <div v-if="row.error_code" class="text-xs text-danger" data-testid="history-error-code">
                                    {{ errorLabel(row.error_code) }}
                                </div>
                                <div
                                    v-if="row.status === 'failed' && row.kind === 'upgrade'"
                                    class="text-xs text-ink-muted"
                                    data-testid="history-upgrade-restored"
                                >
                                    {{ $t('text.modules.install.history.upgrade_restored') }}
                                </div>
                                <div
                                    v-if="row.kind === 'uninstall' && row.status === 'succeeded'"
                                    class="text-xs text-ink-muted"
                                    data-testid="history-uninstall-result"
                                >
                                    {{
                                        $t(
                                            row.confirmed_irreversible
                                                ? 'text.modules.install.history.uninstall_dropped'
                                                : 'text.modules.install.history.uninstall_kept',
                                        )
                                    }}
                                </div>
                                <div v-if="row.backup_skipped && row.kind !== 'uninstall'" class="text-xs text-ink-muted" data-testid="history-backup-skipped">
                                    {{ $t(row.status === 'failed' ? 'text.modules.install.history.backup_skipped_failed' : 'text.modules.install.history.backup_skipped') }}
                                </div>
                                <div v-if="row.status === 'failed' && row.backup_uuid" class="text-xs text-ink-muted">
                                    {{ $t(row.kind === 'uninstall' ? 'text.modules.install.history.uninstall_failed' : 'text.modules.install.history.backup_kept') }}
                                </div>
                                <div v-if="row.third_party_confirmed" class="text-xs text-ink-muted" data-testid="history-third-party">
                                    {{ $t('text.modules.install.history.third_party_confirmed') }}
                                </div>
                                <div v-for="code in warningCodes(row)" :key="code" class="text-xs text-ink-muted">
                                    {{ errorLabel(code) }}
                                </div>
                            </td>
                            <td class="py-2 pr-3">{{ sourceText(row.source) }}</td>
                            <td class="py-2">{{ formatDate(row.started_at || row.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Install drawer -->
        <store-detail-drawer
            v-if="webmaster"
            :open="store.open"
            :entry="storeEntry"
            :locale="locale"
            :install-blocked="installBlocked"
            @close="store.open = false"
            @install="startFromStore(openInstall, $event)"
            @upgrade="startFromStore(openUpgrade, $event)"
            @support="openSupport"
            @rated="applyRated"
        />

        <!-- Bug report / support request with the masked bundle -->
        <support-dialog v-if="webmaster" :open="support.open" :entry="support.entry" @close="support.open = false" />

        <a-drawer
            v-if="webmaster"
            :open="drawer.open"
            width="min(480px, 100vw)"
            :title="
                isUpgrade
                    ? $t('text.modules.install.drawer.upgrade_title', { module: drawer.label })
                    : $t('text.modules.install.drawer.title', { module: drawer.label })
            "
            @close="closeDrawer"
        >
            <form class="space-y-4" data-testid="install-form" novalidate @submit.prevent="submitInstall">
                <h3 v-if="isUpgrade" class="m-0 text-base font-semibold" data-testid="upgrade-heading">
                    {{ $t('text.modules.install.drawer.upgrade_heading', { from: upgradeFrom, to: drawer.version }) }}
                </h3>
                <publisher-badge v-if="drawerPublisher" :publisher="drawerPublisher" />
                <div v-if="drawer.loading" aria-busy="true" data-testid="preflight-loading">
                    <a-skeleton active :paragraph="{ rows: 4 }" />
                </div>
                <div v-else-if="drawer.loadError" role="alert" class="space-y-2" data-testid="preflight-error">
                    <p class="m-0 text-sm text-danger">{{ drawer.loadError }}</p>
                    <i-button size="sm" data-testid="preflight-retry" @click="loadPreflight">
                        {{ $t('text.modules.install.retry') }}
                    </i-button>
                </div>
                <!-- The website itself cannot install right now: one plain reason, nothing else to read or tick -->
                <div v-else-if="siteBlock" class="space-y-3" data-testid="install-site-blocked">
                    <a-alert
                        type="warning"
                        show-icon
                        :message="$t('text.modules.install.blocked.title')"
                        :description="siteBlock.text"
                        data-testid="install-site-blocked-reason"
                    />
                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" data-testid="install-cancel" @click="closeDrawer">
                            {{ $t('text.modules.install.action.cancel') }}
                        </i-button>
                        <i-button
                            v-if="siteBlock.code === 'MODULE_INSTALL_DISABLED'"
                            variant="primary"
                            size="sm"
                            data-testid="install-site-blocked-go"
                            @click="revealInstallSwitch"
                        >
                            {{ $t('text.modules.install.blocked.go_to_switch') }}
                        </i-button>
                    </div>
                </div>
                <template v-else-if="drawer.result">
                    <a-alert
                        v-if="drawer.submitError"
                        type="error"
                        show-icon
                        :message="drawer.submitError"
                        data-testid="install-submit-error"
                    />

                    <div v-if="blockingItems.length" class="space-y-2" data-testid="preflight-blocking">
                        <a-alert
                            v-for="item in blockingItems"
                            :key="item.code"
                            type="error"
                            show-icon
                            :message="item.text"
                            data-testid="preflight-blocking-item"
                        />
                    </div>

                    <section v-if="requirementRows.length" class="space-y-2" data-testid="preflight-requirements">
                        <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.install.drawer.requirements_title') }}</h4>
                        <ul class="m-0 list-none space-y-2 p-0">
                            <li
                                v-for="req in requirementRows"
                                :key="req.key"
                                class="flex flex-wrap items-center justify-between gap-2 rounded border border-border p-2 text-sm"
                                data-testid="preflight-requirement"
                            >
                                <span class="min-w-0 flex-1 basis-48 break-words">
                                    {{ req.text }}
                                    <span v-if="req.hint" class="block text-xs text-ink-muted" data-testid="preflight-requirement-hint">{{ req.hint }}</span>
                                </span>
                                <i-button
                                    v-if="req.action"
                                    size="sm"
                                    data-testid="preflight-requirement-action"
                                    @click="openRequirement(req)"
                                >
                                    {{ req.actionText }}
                                </i-button>
                            </li>
                        </ul>
                    </section>

                    <div v-if="otherWarnings.length" class="space-y-2" data-testid="preflight-warnings">
                        <a-alert
                            v-for="item in otherWarnings"
                            :key="item.code"
                            type="info"
                            show-icon
                            :message="item.text"
                        />
                    </div>

                    <section v-if="dbWarning" class="space-y-2" data-testid="preflight-db-warning">
                        <a-alert
                            type="warning"
                            show-icon
                            :message="dbWarning.title"
                            :description="dbWarning.advice"
                            data-testid="preflight-db-warning-alert"
                        />
                        <ul class="m-0 list-disc space-y-1 pl-5 text-xs" data-testid="preflight-db-warning-facts">
                            <li v-for="fact in dbWarning.facts" :key="fact" class="break-words">{{ fact }}</li>
                        </ul>
                    </section>

                    <div v-if="irreversible" class="space-y-2" data-testid="preflight-irreversible">
                        <a-alert
                            type="warning"
                            show-icon
                            :message="$t('text.modules.install.drawer.irreversible_title')"
                            :description="
                                irreversible.migrations.length
                                    ? $t('text.modules.install.drawer.irreversible_text')
                                    : $t('text.modules.install.drawer.irreversible_text_unlisted')
                            "
                        />
                        <ul
                            v-if="irreversible.migrations.length"
                            data-testid="preflight-irreversible-list"
                            class="m-0 list-disc space-y-1 pl-5 text-xs"
                        >
                            <li v-for="migration in irreversible.migrations" :key="migration" class="break-all">
                                {{ migration }}
                            </li>
                        </ul>
                    </div>

                    <third-party-confirm v-if="thirdParty" v-model="drawer.thirdPartyConfirmed" :details="thirdParty.details" :show-badge="false" />

                    <section v-if="!blockingItems.length" class="space-y-1">
                        <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.install.drawer.steps_title') }}</h4>
                        <ol class="m-0 list-decimal space-y-1 pl-5 text-sm" data-testid="install-steps">
                            <li v-for="step in steps" :key="step">
                                {{ $t('text.modules.install.drawer.step.' + step) }}
                            </li>
                        </ol>
                    </section>

                    <!-- A blocked install takes no backup, so the backup choice and its notice stay away until it can start -->
                    <fieldset v-if="!blockingItems.length" class="m-0 space-y-1 border-0 p-0" data-testid="install-backup">
                        <label class="flex cursor-pointer items-start gap-2">
                            <input
                                v-model="drawer.backup"
                                type="checkbox"
                                class="mt-1"
                                data-testid="install-backup-toggle"
                            />
                            <span class="space-y-1">
                                <span class="block text-sm font-medium">{{ $t('text.modules.install.drawer.backup_label') }}</span>
                                <span v-if="backupReason" class="block text-xs text-ink-muted" data-testid="install-backup-reason">{{ backupReason }}</span>
                            </span>
                        </label>
                        <p class="m-0 pl-6 text-xs text-ink-muted" data-testid="install-backup-last">{{ lastBackupText }}</p>
                        <p class="m-0 pl-6 text-xs text-ink-muted" data-testid="install-backup-notice">
                            {{ $t(backupNoticeKey) }}
                        </p>
                    </fieldset>

                    <label v-if="irreversible && !blockingItems.length" class="block space-y-1">
                        <span class="text-sm font-medium">
                            {{ $t('text.modules.install.drawer.confirm_label', { name: drawer.name }) }}
                        </span>
                        <a-input
                            v-model:value="drawer.typed"
                            :maxlength="64"
                            autocomplete="off"
                            data-testid="install-confirm-name"
                        />
                    </label>

                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" data-testid="install-cancel" @click="closeDrawer">
                            {{ $t('text.modules.install.action.cancel') }}
                        </i-button>
                        <i-button
                            variant="primary"
                            size="sm"
                            native-type="submit"
                            data-testid="install-start"
                            :loading="drawer.installing"
                            :disabled="!canStart"
                        >
                            {{ $t(isUpgrade ? 'text.modules.install.action.upgrade' : 'text.modules.install.action.start') }}
                        </i-button>
                    </div>
                </template>
            </form>
        </a-drawer>

        <!-- Uninstall drawer -->
        <a-drawer
            v-if="webmaster"
            :open="uninstall.open"
            width="min(480px, 100vw)"
            :title="$t('text.modules.install.uninstall.title', { module: uninstall.label })"
            @close="closeUninstall"
        >
            <form class="flex flex-col gap-4" data-testid="uninstall-form" novalidate @submit.prevent="submitUninstall">
                <div v-if="uninstall.loading" aria-busy="true" data-testid="uninstall-loading">
                    <a-skeleton active :paragraph="{ rows: 4 }" />
                </div>
                <div v-else-if="uninstall.loadError" role="alert" class="space-y-2" data-testid="uninstall-load-error">
                    <p class="m-0 text-sm text-danger">{{ uninstall.loadError }}</p>
                    <i-button size="sm" data-testid="uninstall-retry" @click="loadUninstallInfo">
                        {{ $t('text.modules.install.retry') }}
                    </i-button>
                </div>
                <template v-else-if="uninstall.info">
                    <a-alert
                        v-if="uninstall.submitError"
                        type="error"
                        show-icon
                        :message="uninstall.submitError"
                        data-testid="uninstall-submit-error"
                    />
                    <a-alert
                        v-if="uninstallDependents.length"
                        type="error"
                        show-icon
                        :message="$t('text.modules.install.uninstall.dependents', { modules: uninstallDependents.map(labelFor).join(', ') })"
                        data-testid="uninstall-dependents"
                    />

                    <p class="m-0 text-sm" data-testid="uninstall-intro">{{ $t('text.modules.install.uninstall.intro') }}</p>

                    <fieldset v-if="uninstallTables.length" class="m-0 space-y-2 border-0 p-0" data-testid="uninstall-data-choice">
                        <legend class="mb-1 p-0 text-sm font-semibold">{{ $t('text.modules.install.uninstall.data_title') }}</legend>
                        <label class="flex cursor-pointer items-start gap-2">
                            <input
                                v-model="uninstall.dropData"
                                type="radio"
                                name="uninstall-data"
                                :value="false"
                                class="mt-1"
                                data-testid="uninstall-keep"
                            />
                            <span class="text-sm">
                                <span class="block font-medium">{{ $t('text.modules.install.uninstall.keep') }}</span>
                                <span class="block text-xs text-ink-muted">{{ $t('text.modules.install.uninstall.keep_hint') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2">
                            <input
                                v-model="uninstall.dropData"
                                type="radio"
                                name="uninstall-data"
                                :value="true"
                                class="mt-1"
                                data-testid="uninstall-drop"
                            />
                            <span class="text-sm">
                                <span class="block font-medium">{{ $t('text.modules.install.uninstall.drop') }}</span>
                                <span class="block text-xs text-ink-muted">
                                    {{ $t('text.modules.install.uninstall.drop_hint', { count: uninstallTables.length }) }}
                                </span>
                            </span>
                        </label>
                    </fieldset>

                    <label class="block space-y-1">
                        <span class="text-sm font-medium">
                            {{ $t('text.modules.install.uninstall.confirm_label', { name: uninstall.name }) }}
                        </span>
                        <a-input
                            v-model:value="uninstall.typed"
                            :maxlength="64"
                            autocomplete="off"
                            data-testid="uninstall-confirm-name"
                        />
                    </label>

                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" data-testid="uninstall-cancel" @click="closeUninstall">
                            {{ $t('text.modules.install.action.cancel') }}
                        </i-button>
                        <i-button
                            variant="danger"
                            size="sm"
                            native-type="submit"
                            data-testid="uninstall-start"
                            :loading="uninstall.busy"
                            :disabled="!canUninstall"
                        >
                            {{ $t('text.modules.install.action.uninstall') }}
                        </i-button>
                    </div>
                </template>
            </form>
        </a-drawer>

        <!-- Upload drawer -->
        <a-drawer
            v-if="webmaster"
            :open="upload.open"
            width="min(480px, 100vw)"
            :title="
                upload.upgradeTo
                    ? $t('text.modules.install.drawer.upgrade_title', { module: upload.upgradeName })
                    : $t('text.modules.install.upload.title')
            "
            @close="closeUpload"
        >
            <form class="space-y-4" data-testid="upload-form" novalidate @submit.prevent="submitUpload">
                <h3 v-if="upload.upgradeTo" class="m-0 text-base font-semibold" data-testid="upload-upgrade-heading">
                    {{ $t('text.modules.install.drawer.upgrade_heading', { from: upload.upgradeFrom, to: upload.upgradeTo }) }}
                </h3>
                <p class="m-0 text-sm text-ink-muted">{{ $t('text.modules.install.upload.hint') }}</p>
                <p class="m-0 text-sm text-ink-muted" data-testid="upload-upgrade-hint">{{ $t('text.modules.install.upload.upgrade_hint') }}</p>
                <a-alert v-if="upload.error" type="error" show-icon :message="upload.error" data-testid="upload-error" />

                <div class="block space-y-1">
                    <span class="text-sm font-medium">{{ $t('text.modules.install.upload.zip_label') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <i-button size="sm" data-testid="upload-zip-pick" @click="zipInput?.click()">
                            <template #icon><i-icon icon="upload" class="size-4" /></template>
                            {{ $t('text.modules.install.upload.pick_zip') }}
                        </i-button>
                        <span class="break-all text-xs text-ink-muted" data-testid="upload-zip-name">
                            {{ upload.zip ? upload.zip.name : $t('text.modules.install.upload.no_file') }}
                        </span>
                    </div>
                    <input
                        ref="zipInput"
                        type="file"
                        accept=".zip,application/zip"
                        class="hidden"
                        data-testid="upload-zip"
                        @change="onZipChange"
                    />
                </div>
                <div class="block space-y-1">
                    <span class="text-sm font-medium">{{ $t('text.modules.install.upload.signature_label') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <i-button size="sm" data-testid="upload-signature-pick" @click="sigInput?.click()">
                            <template #icon><i-icon icon="upload" class="size-4" /></template>
                            {{ $t('text.modules.install.upload.pick_signature') }}
                        </i-button>
                        <span class="break-all text-xs text-ink-muted" data-testid="upload-signature-name">
                            {{ upload.signature ? upload.signature.name : $t('text.modules.install.upload.no_file') }}
                        </span>
                    </div>
                    <input
                        ref="sigInput"
                        type="file"
                        accept=".json,application/json"
                        class="hidden"
                        data-testid="upload-signature"
                        @change="onSignatureChange"
                    />
                </div>

                <label class="flex cursor-pointer items-start gap-2" data-testid="upload-backup">
                    <input v-model="upload.backup" type="checkbox" class="mt-1" data-testid="upload-backup-toggle" />
                    <span class="text-sm">{{ $t('text.modules.install.drawer.backup_label') }}</span>
                </label>
                <p v-if="!upload.backup" class="m-0 text-xs text-ink-muted" data-testid="upload-backup-off">
                    {{ $t('text.modules.install.drawer.backup_off_notice') }}
                </p>
                <div v-if="upload.needConfirm" class="space-y-2" data-testid="upload-confirm">
                    <a-alert
                        type="warning"
                        show-icon
                        :message="$t('text.modules.install.drawer.irreversible_title')"
                        :description="$t('text.modules.install.upload.confirm_text')"
                    />
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.install.upload.confirm_label') }}</span>
                        <a-input
                            v-model:value="upload.typed"
                            :maxlength="64"
                            autocomplete="off"
                            data-testid="upload-confirm-name"
                        />
                    </label>
                </div>
                <third-party-confirm v-if="upload.thirdParty" v-model="upload.thirdPartyConfirmed" :details="upload.thirdParty" />
                <p v-if="upload.backup" class="m-0 text-xs text-ink-muted">
                    {{ $t(upload.upgradeTo ? 'text.modules.install.drawer.upgrade_backup_notice' : 'text.modules.install.drawer.backup_notice') }}
                </p>

                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="sm" @click="closeUpload">{{ $t('text.modules.install.action.cancel') }}</i-button>
                    <i-button
                        variant="primary"
                        size="sm"
                        native-type="submit"
                        data-testid="upload-start"
                        :loading="upload.busy"
                        :disabled="!canUpload"
                    >
                        {{ $t(upload.upgradeTo ? 'text.modules.install.action.upgrade' : 'text.modules.install.action.start') }}
                    </i-button>
                </div>
            </form>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { LockOutlined, SyncOutlined } from '@ant-design/icons-vue'
import { iAlert } from '@/mixins'
import { useModules } from '@/hooks'
import { isWebmaster } from '@/hooks/useCan'
import {
    MODULE_SITE_BLOCK_CODES,
    MODULE_PACKAGE_MAX_BYTES,
    MODULE_SIGNATURE_MAX_BYTES,
    getModuleCatalog,
    getModuleInstalls,
    getModuleUninstallInfo,
    getModulePreflight,
    installModule,
    moduleErrorCodeOf,
    moduleErrorKey,
    uninstallModule,
    updateModuleStatus,
    uploadModulePackage,
} from '@/libs/module'
import mainNav from '@/config/navigation'
import StoreTab from './modules/store-tab.vue'
import StoreDetailDrawer from './modules/store-detail-drawer.vue'
import SupportDialog from './modules/support-dialog.vue'
import SupportRequests from './modules/support-requests.vue'
import IntegrityNote from './modules/integrity-note.vue'
import ReportLevel from './modules/report-level.vue'
import PublisherBadge from './modules/publisher-badge.vue'
import ThirdPartyConfirm from './modules/third-party-confirm.vue'
import CoreUpdateCard from './modules/core-update-card.vue'
import HubConnectDrawer from './modules/hub-connect-drawer.vue'
import InstallSwitch from './modules/install-switch.vue'
import ajax from '@/ajax'

defineComponent({
    name: 'SettingModules',
})

/** Milliseconds between install list refreshes while an install is unfinished. */
const INSTALL_POLL_MS = 5000
const FINISHED_STATUSES = ['succeeded', 'failed']
const WARN_IRREVERSIBLE = 'MODULE_WARN_IRREVERSIBLE_MIGRATIONS'
const WARN_CUSTOM_CODE = 'MODULE_WARN_CUSTOM_CODE'
/** First install of a module that iSPA does not publish: shown as its own confirmation step. */
const WARN_THIRD_PARTY = 'MODULE_WARN_THIRD_PARTY_FIRST_INSTALL'
/** Folder name that marks a module installed at runtime (not shipped in the release). */
const RUNTIME_FOLDER = 'runtime-modules'

const { t, locale } = useI18n()
const switching = ref({})
const search = ref('')
const tab = ref('installed')
const { data, isLoading, isRefetching, refetch } = useModules()

/** Only a Webmaster switches modules, installs or uploads packages; everyone else sees the list. */
const webmaster = computed(() => isWebmaster())

/** The "Connect to iSPA Hub" drawer. */
const hubConnectOpen = ref(false)
/** The drawer opens on the site address + secret form (the one-tap callback screen links here with ?connect=manual). */
const hubConnectManual = ref(false)

/** The drawer component: the Modules button calls its `start()` and reads `starting` / `waiting`. */
const hubDrawer = ref(null)

/**
 * Open the connect drawer.
 *
 * @param {boolean} manual Open it on the site address + secret form; otherwise on what the one-tap start said.
 */
const openHubConnect = (manual = false) => {
    hubConnectManual.value = manual === true
    hubConnectOpen.value = true
}

/**
 * The site is now connected: read the module list again, which also loads the hub catalog. The drawer stays open on
 * its success message (and the .env notice, when there is one) until the person closes it.
 */
const onHubConnected = async () => {
    await refetch()
}

/**
 * Split a CamelCase class name into words ("SampleModuleName" -> "Sample Module Name").
 */
const humanize = (name) =>
    String(name || '')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
        .trim()

/**
 * Lower-case a text and strip diacritics so Vietnamese searches match without accents.
 */
const normalize = (value) =>
    String(value || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()

/**
 * Map ModuleName -> translated text of the first sidebar root item owned by that module.
 */
const navLabels = computed(() => {
    const map = {}
    for (const item of Array.isArray(mainNav) ? mainNav : []) {
        if (!item?.module || map[item.module]) {
            continue
        }
        const key = Array.isArray(item.text) ? item.text[0] : item.text
        if (!key) {
            continue
        }
        const translated = t(key)
        if (translated && translated !== key) {
            map[item.module] = translated
        }
    }
    return map
})

/**
 * Kebab-case translation key segment of a module ("SampleModuleName" -> "sample-module-name").
 */
const moduleKey = (name) =>
    String(name || '')
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .toLowerCase()

/**
 * Translation of nav.module.<kebab>.<field>, or an empty string when no translation exists.
 * The global missing handler answers a missing key with its humanized last segment ("Name", "Description"),
 * so that answer counts as missing too (a runtime module that ships no translations).
 */
const moduleText = (name, field) => {
    const key = `nav.module.${moduleKey(name)}.${field}`
    const translated = t(key)
    const missingFallback = field.charAt(0).toUpperCase() + field.slice(1)
    return translated && translated !== key && translated !== missingFallback ? translated : ''
}

/**
 * Readable label of a module: translated module name, else sidebar label, else the humanized class name.
 */
const readableLabel = (name) =>
    moduleText(name, 'name') || navLabels.value[name] || humanize(name)

/**
 * Lookup of lower-case module names and aliases to readable labels.
 */
const labelLookup = computed(() => {
    const map = {}
    for (const m of data.value || []) {
        const label = readableLabel(m.name)
        map[String(m.name).toLowerCase()] = label
        if (m.alias) {
            map[String(m.alias).toLowerCase()] = label
        }
    }
    return map
})

/**
 * Resolve a module name or alias (any case) to its readable label, else return it unchanged.
 */
const labelFor = (ref) => labelLookup.value[String(ref).toLowerCase()] || ref

/**
 * Where an installed module lives: `runtime` when its folder is the runtime modules folder, else `release`.
 * The folder itself is never shown.
 */
const sourceOf = (path) =>
    String(path || '')
        .split(/[\\/]/)
        .includes(RUNTIME_FOLDER)
        ? 'runtime'
        : 'release'

/**
 * Translation keys for a quarantined runtime module, keyed by the machine code of the API.
 * The code and the English reason of the API are never shown: an unknown code falls back to the generic reason.
 */
const QUARANTINE_REASON_KEYS = {
    PROVIDER_CLASS_MISSING: 'text.modules.quarantine.reason.load',
    AUTOLOAD_ROOT_MISSING: 'text.modules.quarantine.reason.files',
    MODULE_INVALID: 'text.modules.quarantine.reason.invalid',
}

/**
 * Describe the quarantine state of a module row.
 * `off`: the module was switched off because it could not load.
 * `shadow`: a runtime copy with the name of this release module was ignored; the module itself keeps running.
 * For a module listed in runtime_modules.json (`handover`,) that copy is the online one, not newer than the
 * release copy: nothing needs removing, and removing it would only leave the site on the release copy.
 *
 * @returns {{kind: 'off' | 'shadow', tagKey?: string, messageKey: string, actionKey: string} | null}
 */
const quarantineOf = (m) => {
    if (!m.quarantined) {
        return null
    }
    if (m.quarantineCode === 'MODULE_SHADOWS_RELEASE') {
        const group = m.handover === true ? 'listed' : 'shadow'
        return {
            kind: 'shadow',
            tagKey: `text.modules.quarantine.${group}.tag`,
            messageKey: `text.modules.quarantine.${group}.note`,
            actionKey: `text.modules.quarantine.${group}.action`,
        }
    }

    return {
        kind: 'off',
        messageKey: QUARANTINE_REASON_KEYS[m.quarantineCode] || 'text.modules.quarantine.reason.load',
        actionKey: 'text.modules.quarantine.action',
    }
}

/**
 * Modules with a readable label, Core first and then sorted by label in the current locale.
 */
const moduleList = computed(() => {
    if (!data.value) {
        return []
    }

    return data.value
        .map((m) => ({
            ...m,
            label: readableLabel(m.name),
            source: sourceOf(m.path),
            quarantine: quarantineOf(m),
            description: moduleText(m.name, 'description') || m.description,
        }))
        .sort((a, b) => {
            if (a.switchable === false && b.switchable !== false) {
                return -1
            }
            if (b.switchable === false && a.switchable !== false) {
                return 1
            }

            return a.label.localeCompare(b.label, locale?.value || undefined)
        })
})

/**
 * Module list narrowed by the search box (label, name, alias, description; accent-insensitive).
 */
const filteredList = computed(() => {
    const needle = normalize(search.value).trim()
    if (!needle) {
        return moduleList.value
    }

    return moduleList.value.filter((m) =>
        [m.label, m.name, m.alias, m.description].some((v) => normalize(v).includes(needle)),
    )
})

/**
 * Work out whether a module's switch is locked and the reason to show the operator.
 */
const switchMeta = (module) => {
    const missing = module?.missing_dependencies || []
    const dependents = module?.blocking_dependents || []

    if (!module?.switchable) {
        return {
            disabled: true,
            reason: t('text.modules.core_locked'),
        }
    }

    if (!module.enabled && missing.length) {
        return {
            disabled: true,
            reason: t('text.modules.activate_after', {
                module: module.label,
                deps: missing.map(labelFor).join(', '),
            }),
        }
    }

    if (module.enabled && dependents.length) {
        return {
            disabled: true,
            reason: t('text.modules.disable_dependents', {
                module: module.label,
                deps: dependents.map(labelFor).join(', '),
            }),
        }
    }

    if (switching.value[module.name]) {
        return {
            disabled: true,
            reason: '',
        }
    }

    return {
        disabled: false,
        reason: '',
    }
}

/**
 * Switch a module on or off, guarding against double submits and refetching afterwards.
 */
const toggleModule = async (module, enabled) => {
    if (!module?.name) {
        return
    }

    if (switching.value[module.name]) {
        return
    }

    switching.value = { ...switching.value, [module.name]: true }

    try {
        const response = await updateModuleStatus(module.name, { enabled })
        if (response?.message) {
            iAlert(response)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        switching.value = { ...switching.value, [module.name]: false }
        await refetch()
    }
}

// ---- "Cho phép cài module" ------------------------------------------------------------------------------------------

/** Last answer of the install-setting API (from the switch card); null until it loads, and when it cannot be read. */
const installSetting = ref(null)
const installSwitch = ref(null)

/**
 * Installs are known to be blocked for the whole website. An unread setting does not block here: the preflight of
 * the install drawer still decides.
 */
const installBlocked = computed(() => Boolean(installSetting.value?.state) && installSetting.value.state !== 'allowed')

/** The plain sentence for why installs are blocked. */
const installBlockedKey = computed(
    () =>
        ({
            forbidden_by_server: 'text.modules.error.install_forbidden_by_server',
            forbidden_by_hub: 'text.modules.error.install_forbidden_by_hub',
        })[installSetting.value?.state] || 'text.modules.error.install_disabled',
)

/** Close whatever is open and bring the switch into view. */
const revealInstallSwitch = async () => {
    closeDrawer()
    store.open = false
    await nextTick()
    installSwitch.value?.reveal()
}

// ---- Error text --------------------------------------------------------------------------------------------------

/**
 * Translated text of a machine code (error or warning); unknown codes get the generic message.
 */
const errorLabel = (code) => {
    const key = moduleErrorKey(code)
    return key ? t(key) : ''
}

/** The hub refused this site's connection (its 401); not a network outage, so it gets its own plain words. */
const HUB_NOT_RECOGNISED = 'MODULE_HUB_NOT_RECOGNISED'

/**
 * Whole minutes a rate-limited request has to wait, from its `Retry-After` header (seconds), rounded up and at least
 * one; null when the header is missing or unreadable.
 */
const retryAfterMinutes = (error) => {
    const headers = error?.response?.headers
    const raw = typeof headers?.get === 'function' ? headers.get('retry-after') : (headers?.['retry-after'] ?? headers?.['Retry-After'])
    const seconds = Number(raw)
    return raw === null || raw === undefined || raw === '' || !Number.isFinite(seconds) || seconds <= 0 ? null : Math.max(1, Math.ceil(seconds / 60))
}

/**
 * Translated text of a failed request: the wait for a rate-limited one (HTTP 429), else its machine code, else the
 * given fallback key.
 */
const errorText = (error, fallbackKey, params = {}) => {
    if (error?.response?.status === 429) {
        const minutes = retryAfterMinutes(error)
        return minutes === null ? t('text.modules.error.too_many_starts_soon') : t('text.modules.error.too_many_starts', { minutes })
    }
    const code = moduleErrorCodeOf(error)
    if (code === HUB_NOT_RECOGNISED) return t('text.modules.error.hub_not_recognised')
    const key = moduleErrorKey(code)
    return key ? t(key, params) : t(fallbackKey)
}

/**
 * Format an ISO date in the admin locale, or "-" when it is missing or invalid.
 */
const formatDate = (iso) => {
    if (!iso) return '-'
    const date = new Date(iso)
    return Number.isNaN(date.getTime())
        ? '-'
        : date.toLocaleString(locale?.value || undefined, { dateStyle: 'short', timeStyle: 'short' })
}

// ---- Integrity report ----------------------------------------------------------------------------------

const integrity = reactive({ loaded: false, checking: false, error: '', checkedAt: null, modules: {} })

/**
 * Apply a stored or fresh integrity result (`{checked_at, modules}`) to the page state.
 */
const applyIntegrity = (payload) => {
    integrity.checkedAt = payload?.checked_at || null
    integrity.modules = payload?.modules && typeof payload.modules === 'object' ? payload.modules : {}
    integrity.loaded = true
}

/**
 * Read the last stored result. A failure is quiet: the notes simply stay hidden and "Check files now" still works.
 */
const loadIntegrity = async () => {
    try {
        const answer = await ajax.get('utilities/modules/integrity', { silent: true })
        applyIntegrity(answer.data?.data)
    } catch {
        integrity.loaded = true
    }
}

/**
 * Compare the files now. The first press blocks the next ones; a failure says so in plain words and can be retried.
 */
const runIntegrityCheck = async () => {
    if (integrity.checking) return
    integrity.checking = true
    integrity.error = ''
    try {
        const answer = await ajax.post('utilities/modules/integrity/check', {}, { silent: true })
        applyIntegrity(answer.data?.data)
    } catch {
        integrity.error = t('text.modules.integrity.check_failed')
    } finally {
        integrity.checking = false
    }
}

// ---- Data blocks -------------------------------------------------------------------------------------------------

const makeBlock = () => reactive({ loading: false, loaded: false, error: '', data: [] })
const catalog = makeBlock()
const installs = makeBlock()

let disposed = false
let pollTimer = null
let hadUnfinished = false

/**
 * Whether a hub is connected, read from the modules list rows (`hub_configured`).
 * `null` while the list is not loaded yet; a row without the flag counts as configured.
 */
const hubConfigured = computed(() => {
    const rows = Array.isArray(data.value) ? data.value : []
    if (!rows.length) return null
    return !rows.every((m) => m.hub_configured === false)
})

// The dashboard band links here with ?connect=1 (the one-tap callback screen with ?connect=manual, for the manual form). The modules list may be cached from before the site connected, so it
// is reloaded first; a Webmaster whose site is still not connected then lands on the module store tab with the connect
// drawer open. The flag is removed from the address either way, so a reload or the back button does not open it again.
const route = useRoute()
const router = useRouter()
const openConnectFromLink = async () => {
    const wanted = route?.query?.connect
    if (wanted !== '1' && wanted !== 'manual') return
    await refetch()
    if (webmaster.value && hubConfigured.value === false) {
        tab.value = 'available'
        hubConnectManual.value = wanted === 'manual'
        hubConnectOpen.value = true
    }
    const query = { ...route.query }
    delete query.connect
    router?.replace({ query })
}
openConnectFromLink()

/**
 * Load the hub catalog; a failure shows its message with a retry, never an empty list.
 */
const loadCatalog = async () => {
    if (hubConfigured.value !== true) return
    catalog.loading = true
    catalog.error = ''
    try {
        catalog.data = await getModuleCatalog()
        catalog.loaded = true
    } catch (error) {
        catalog.error = errorText(error, 'text.modules.install.catalog_failed')
    } finally {
        catalog.loading = false
    }
}

/**
 * Plan the next install list refresh: fast while an install is unfinished. When the last unfinished install ends,
 * the installed list and the catalog are reloaded because the module list changed.
 */
const scheduleInstallPoll = () => {
    clearTimeout(pollTimer)
    pollTimer = null
    if (disposed) return
    const unfinished = installs.data.some((row) => !FINISHED_STATUSES.includes(row?.status))
    if (unfinished) {
        hadUnfinished = true
        pollTimer = setTimeout(() => loadInstalls(true), INSTALL_POLL_MS)
    } else if (hadUnfinished) {
        hadUnfinished = false
        refetch()
        if (catalog.loaded) loadCatalog()
    }
}

/**
 * Load the latest installs. A silent refresh (the poll) keeps the last list when it fails.
 */
const loadInstalls = async (silent = false) => {
    if (!silent) {
        installs.loading = true
        installs.error = ''
    }
    try {
        installs.data = await getModuleInstalls()
        installs.loaded = true
    } catch (error) {
        if (!silent) installs.error = errorText(error, 'text.modules.install.history_failed')
    } finally {
        installs.loading = false
        scheduleInstallPoll()
    }
}

/**
 * Load a tab's data the first time it opens.
 */
const onTabChange = (key) => {
    if (key === 'available' && !catalog.loaded && !catalog.loading) loadCatalog()
    if (key === 'history' && !installs.loading) loadInstalls()
}

/**
 * Reload the installed list and whatever else is loaded.
 */
const reloadAll = () => {
    refetch()
    if (!webmaster.value) return
    if (catalog.loaded || tab.value === 'available') loadCatalog()
    loadInstalls()
    loadIntegrity()
}

watch(
    [webmaster, hubConfigured],
    ([value, hub]) => {
        if (!value) return
        if (!installs.loaded && !installs.loading) loadInstalls()
        if (!integrity.loaded) loadIntegrity()
        // The installed list shows "new version" offers, so the hub catalog is read once up front (its errors stay on the Available tab).
        // Without a connected hub there is nothing to ask, so no request is sent.
        if (hub === true && !catalog.loaded && !catalog.loading) loadCatalog()
    },
    { immediate: true },
)

onBeforeUnmount(() => {
    disposed = true
    clearTimeout(pollTimer)
})

// ---- Catalog -----------------------------------------------------------------------------------------------------

/**
 * True when a hub `irreversible` value names migrations (anything but false, null or an empty list).
 */
const hasIrreversible = (value) =>
    value !== false && value !== null && value !== undefined && !(Array.isArray(value) && value.length === 0)

/**
 * Names of the lower-case modules that are installed and enabled on this site (names and aliases).
 */
const enabledNames = computed(() => {
    const names = new Set()
    for (const m of data.value || []) {
        if (!m.enabled) continue
        names.add(String(m.name).toLowerCase())
        if (m.alias) names.add(String(m.alias).toLowerCase())
    }
    return names
})

/**
 * Requirements of a version as `[{ name, met }]`. The hub sends a list of names or an object of name -> constraint;
 * "met" only checks that the module is enabled here, the install preflight judges versions.
 */
const requirementsOf = (requires) => {
    let names = []
    if (Array.isArray(requires)) {
        names = requires.filter((item) => typeof item === 'string')
    } else if (requires && typeof requires === 'object') {
        names = Object.keys(requires)
    }
    return names.map((name) => ({ name, met: enabledNames.value.has(String(name).toLowerCase()) }))
}

/**
 * Catalog entries prepared for display, with the latest version and whether it can be started.
 */
const catalogRows = computed(() =>
    (catalog.data || []).map((entry) => {
        const versions = Array.isArray(entry.versions) ? entry.versions : []
        const latest =
            versions.find((v) => v.version === entry.latest_version) || versions[versions.length - 1] || null
        const installed = entry.installed || 'none'
        const revoked = Boolean(latest?.revoked)

        return {
            ...entry,
            label: readableLabel(entry.name),
            latest,
            installed,
            revoked,
            irreversible: hasIrreversible(latest?.irreversible),
            requirements: requirementsOf(latest?.requires),
            canInstall: Boolean(latest) && installed === 'none' && !revoked,
            // A release module listed in runtime_modules.json (handover) may get a newer online copy too.
            canUpgrade:
                (installed === 'runtime' || entry.handover === true) &&
                entry.upgrade_available === true &&
                Boolean(entry.upgrade_version),
        }
    }),
)

/** The platform module every site has; it is updated together with iSPA CMS (core update card), never on its own. */
const CORE_MODULE = 'Core'
const isCore = (module) => module?.name === CORE_MODULE

/**
 * Upgrade offer of an installed runtime module (`{ name, label, upgrade_version, installed_version }`), or null.
 * Release modules are updated with the site and never offered here, except one listed in the site's
 * runtime_modules.json (handover,), whose release copy stays as the fallback.
 */
const upgradeFor = (name) => {
    if (name === CORE_MODULE) return null
    if ((data.value || []).some((m) => m.name === name && m.updates_unavailable === true)) return null
    const entry = catalogRows.value.find((row) => row.name === name && row.canUpgrade)
    return entry ? { ...entry, version: entry.upgrade_version } : null
}

/**
 * Whether no update is offered for an installed module: its access ended (expired or withdrawn), or it is a runtime
 * module the hub does not list any more. The module keeps running either way.
 */
const updatesUnavailable = (module) =>
    module.updates_unavailable === true ||
    (module.source === 'runtime' &&
        catalogRows.value.some((row) => row.name === module.name && row.updates_unavailable === true))

/**
 * Format an ISO date as a short date in the admin locale, or "-" when missing or invalid.
 */
const formatDay = (iso) => {
    if (!iso) return '-'
    const date = new Date(iso)
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString(locale?.value || undefined, { dateStyle: 'short' })
}

/**
 * Badge colour of an entitlement state.
 */
const entitlementColor = (state) => ({ entitled: 'success', grace: 'warning', expired: 'error', revoked: 'error' })[state] || 'default'

/**
 * Badge text of an entitlement ({ state, expires_at, grace_until }).
 */
const entitlementLabel = (entitlement) =>
    t('text.modules.entitlement.state.' + entitlement.state, { date: formatDay(entitlement.grace_until) })

/**
 * Tooltip with the dates of an entitlement, or undefined when it has none.
 */
const entitlementTooltip = (entitlement) => {
    const lines = []
    if (entitlement.expires_at) lines.push(t('text.modules.entitlement.tooltip.expires', { date: formatDay(entitlement.expires_at) }))
    if (entitlement.grace_until) lines.push(t('text.modules.entitlement.tooltip.grace_until', { date: formatDay(entitlement.grace_until) }))
    return lines.length ? lines.join(' · ') : undefined
}

/**
 * Sentence for a module in grace, expired or withdrawn (the "contact iSPA" note), or '' when none applies.
 */
const entitlementNote = (module) => {
    const state = module.entitlement?.state
    if (!['grace', 'expired', 'revoked'].includes(state)) return ''
    return t('text.modules.entitlement.note.' + state, { name: module.label, date: formatDay(module.entitlement.grace_until) })
}

/**
 * Catalog narrowed by the search box.
 */
const filteredCatalog = computed(() => {
    const needle = normalize(search.value).trim()
    if (!needle) return catalogRows.value
    return catalogRows.value.filter((entry) => [entry.label, entry.name].some((v) => normalize(v).includes(needle)))
})

// ---- Store detail drawer -----------------------------------------------------------------------------------------

const store = reactive({ open: false, name: '' })

/** Catalog row of the module shown in the store drawer (fresh after a catalog reload). */
/** A rating saved in the drawer: the card behind it shows the new average at once, with no reload of the tab. */
const applyRated = ({ name, average, count }) => {
    const row = (catalog.data || []).find((item) => item.name === name)
    if (!row || !Number.isFinite(average)) return
    row.listing = { ...(row.listing || {}), rating: { average, count } }
}

const storeEntry = computed(() => catalogRows.value.find((row) => row.name === store.name) || null)

/**
 * The module's own description from the installed list, used on a store card that has no listing.
 */
const describeModule = (name) => (data.value || []).find((m) => m.name === name)?.description || ''

/**
 * Open the store page of a catalog entry.
 */
const openStore = (entry) => {
    store.name = entry.name
    store.open = true
}

/**
 * Install or upgrade pressed inside the store page: close it and run the normal install drawer (same preflight).
 */
const startFromStore = (start, entry) => {
    store.open = false
    start(entry)
}

// ---- Support dialog ----------------------------------------------------------------------------------------------

const support = reactive({ open: false, entry: null })

/**
 * Open "Báo lỗi / Cần hỗ trợ" for an installed module (an installed-list row or a catalog row).
 */
const openSupport = (module) => {
    support.entry = { name: module.name, label: module.label || labelFor(module.name) }
    support.open = true
}

// ---- Install drawer ----------------------------------------------------------------------------------------------

const drawer = reactive({
    open: false,
    name: '',
    version: '',
    label: '',
    loading: false,
    loadError: '',
    result: null,
    typed: '',
    installing: false,
    submitError: '',
    upgrade: false,
    from: '',
    backup: true,
    publisher: null,
    thirdPartyConfirmed: false,
})
let preflightToken = 0

/**
 * Translation parameters for a blocking reason or warning from its details.
 */
const detailParams = (item) => {
    const details = item?.details || {}
    return {
        modules: (details.unmet || details.dependents || []).map((u) => labelFor(u.module)).join(', '),
        extensions: (details.extensions || []).join(', '),
        installed: details.installed_version ?? '',
        required: details.required ?? '',
        running: details.running ?? '',
        count: details.count ?? 0,
        tables: (details.tables || []).join(', '),
    }
}

/**
 * Text of one preflight item (blocking reason or warning).
 */
const describeItem = (item) => {
    const key = moduleErrorKey(item?.code)
    return key ? t(key, detailParams(item)) : ''
}

const blockingItems = computed(() =>
    (drawer.result?.blocking || []).map((item) => ({ code: item.code, text: describeItem(item) })),
)

/**
 * The one reason shown when the website itself cannot install (switch off, locked by the server or the hub, no
 * background work): the most decisive site-wide code of the preflight, or null when only this module is blocked.
 */
const siteBlock = computed(() => {
    for (const code of MODULE_SITE_BLOCK_CODES) {
        const found = blockingItems.value.find((item) => item.code === code)
        if (found) return found
    }
    return null
})

/** Irreversible-migration warning of the preflight with its migration names, or null. */
const irreversible = computed(() => {
    const warning = (drawer.result?.warnings || []).find((item) => item.code === WARN_IRREVERSIBLE)
    if (!warning) return null
    const list = warning.details?.migrations
    return { migrations: Array.isArray(list) ? list.map(String) : [] }
})

/** The partner-module warning of the preflight, or null for an iSPA module or one confirmed before. */
const thirdParty = computed(() => (drawer.result?.warnings || []).find((item) => item.code === WARN_THIRD_PARTY) || null)

/** Publisher shown in the install preview: from the partner warning, else from the catalog entry. */
const drawerPublisher = computed(() => thirdParty.value?.details?.publisher || drawer.publisher || null)

/** Warnings other than the irreversible and partner ones (custom code, resource-only). */
const otherWarnings = computed(() =>
    (drawer.result?.warnings || [])
        .filter((item) => item.code !== WARN_IRREVERSIBLE && item.code !== WARN_THIRD_PARTY)
        .map((item) => ({ code: item.code, text: describeItem(item) })),
)

/** True when the preflight (or the drawer opened from an upgrade offer) is an upgrade of an installed module. */
const isUpgrade = computed(() => drawer.result?.kind === 'upgrade' || (drawer.upgrade && !drawer.result))

/** Version an upgrade replaces: from the preflight, else from the catalog entry the drawer was opened with. */
const upgradeFrom = computed(() => drawer.result?.previous_version || drawer.from || '-')

/** Plain-language steps an install runs, in order. */
const steps = computed(() => {
    const list = isUpgrade.value
        ? ['backup', 'download', 'keep_previous', 'place_upgrade', 'migrate_upgrade']
        : ['backup', 'download', 'place', 'migrate']
    if (!drawer.backup) list.shift()
    if ((drawer.result?.warnings || []).some((item) => item.code === WARN_CUSTOM_CODE)) list.push('rebuild')
    return list
})

/** Why the backup switch starts on or off, from the preflight `backup.reason`; '' when unknown. */
const backupReason = computed(() => {
    const reason = drawer.result?.backup?.reason
    return reason ? t('text.modules.install.drawer.backup_reason.' + reason) : ''
})

/** When this site last backed up on its own (scheduled or nightly), next to the switch. */
const lastBackupText = computed(() => {
    const last = drawer.result?.backup?.last_backup
    if (!last?.at || !['scheduled', 'nightly'].includes(last.source)) return t('text.modules.install.drawer.backup_last.none')
    return t('text.modules.install.drawer.backup_last.' + last.source, { date: formatDate(last.at) })
})

/**
 * Database warning shown before anything is downloaded: for a version with new
 * migrations, or whose migration list is unknown. Says how many data changes run, which existing tables they change,
 * whether some can not be undone, when the site last backed up, and to keep the backup on or back up first. On a
 * reinstall over tables an earlier uninstall kept (`earlier_data`,) it names those tables first.
 * Null when the version changes nothing in the database (or the server sent no advice).
 */
const dbWarning = computed(() => {
    const advice = drawer.result?.backup
    if (!advice?.reason || advice.reason === 'no_new_migrations') return null
    const key = 'text.modules.install.drawer.db_warning.'
    const facts = []
    // Tables kept by an earlier uninstall come first: the module will use their old rows again.
    const earlier = Array.isArray(advice.earlier_tables) ? advice.earlier_tables : []
    if (earlier.length) facts.push(t(key + 'earlier_tables', { tables: earlier.join(', ') }))
    if (advice.reason === 'unknown') facts.push(t(key + 'unknown'))
    else if (advice.new_migrations > 0) facts.push(t(key + 'count', { count: advice.new_migrations }))
    const tables = Array.isArray(advice.altered_tables) ? advice.altered_tables : []
    if (tables.length) facts.push(t(key + 'tables', { tables: tables.join(', ') }))
    else if (advice.reason === 'alters_existing') facts.push(t(key + 'tables_unnamed'))
    else if (advice.reason === 'own_tables_only') facts.push(t(key + 'own_tables'))
    if (irreversible.value) facts.push(t(key + (irreversible.value.migrations.length ? 'irreversible_listed' : 'irreversible')))
    facts.push(lastBackupText.value)
    return {
        title: t(key + ({ unknown: 'title_unknown', earlier_data: 'title_earlier' }[advice.reason] || 'title')),
        advice: t(key + (isUpgrade.value ? 'advice_upgrade' : 'advice')),
        facts,
    }
})

/** What happens to the data with the switch as it is now. */
const backupNoticeKey = computed(() => {
    if (drawer.backup) return isUpgrade.value ? 'text.modules.install.drawer.upgrade_backup_notice' : 'text.modules.install.drawer.backup_notice'
    return isUpgrade.value ? 'text.modules.install.drawer.upgrade_backup_off_notice' : 'text.modules.install.drawer.backup_off_notice'
})

/**
 * A version constraint in words ("bản 2026.11.1 trở lên"); parts joined by ", ". An unknown part is shown as is.
 */
const constraintText = (constraint) => {
    const raw = String(constraint ?? '*').trim()
    if (raw === '' || raw === '*') return t('text.modules.install.constraint.any')
    const names = { '>=': 'gte', '>': 'gt', '<=': 'lte', '<': 'lt', '=': 'eq', '': 'eq' }
    return raw
        .split(',')
        .map((part) => {
            const match = part.trim().match(/^(>=|<=|>|<|=)?\s*(\d+(?:\.\d+)*)$/)
            return match ? t('text.modules.install.constraint.' + names[match[1] || ''], { version: match[2] }) : part.trim()
        })
        .join(', ')
}

/**
 * The module the preflight says must be dealt with first, as rows: what is missing, which version is needed, which
 * one is installed, and the drawer that fixes it (upgrade or install of that module) when the hub offers one.
 */
const requirementRows = computed(() => {
    const rows = []
    for (const item of drawer.result?.blocking || []) {
        if (item.code === 'MODULE_REQUIRES_UNMET') {
            for (const req of item.details?.unmet || []) {
                const name = String(req.module || '')
                const reason = ['missing', 'disabled', 'version'].includes(req.reason) ? req.reason : 'version'
                // "needs any version" reads oddly: a missing or switched-off module with no range gets the short text.
                const anyVersion = reason !== 'version' && ['', '*'].includes(String(req.constraint ?? '*').trim())
                const offer = reason === 'disabled' ? null : upgradeFor(name)
                const fresh = reason === 'missing' ? catalogRows.value.find((row) => row.name === name && row.canInstall) : null
                rows.push({
                    key: 'unmet-' + name,
                    text: t('text.modules.install.drawer.requirement.' + reason + (anyVersion ? '_any' : ''), {
                        module: labelFor(name),
                        wanted: constraintText(req.constraint),
                        installed: req.installed_version || '-',
                    }),
                    action: offer ? 'upgrade' : fresh ? 'install' : '',
                    entry: offer || fresh,
                    actionText: t('text.modules.install.drawer.requirement.' + (offer ? 'upgrade_action' : 'install_action'), { module: labelFor(name) }),
                    hint: reason !== 'disabled' && !offer && !fresh ? t('text.modules.install.drawer.requirement.no_offer', { module: labelFor(name) }) : '',
                })
            }
        }
        if (item.code === 'MODULE_DEPENDENT_PINS_VERSION') {
            for (const dep of item.details?.dependents || []) {
                const name = String(dep.module || '')
                const offer = upgradeFor(name)
                rows.push({
                    key: 'pin-' + name,
                    text: t('text.modules.install.drawer.requirement.pinned', { module: labelFor(name), wanted: constraintText(dep.constraint) }),
                    action: offer ? 'upgrade' : '',
                    entry: offer,
                    actionText: t('text.modules.install.drawer.requirement.upgrade_action', { module: labelFor(name) }),
                    hint: offer ? '' : t('text.modules.install.drawer.requirement.no_offer', { module: labelFor(name) }),
                })
            }
        }
    }
    return rows
})

/**
 * Switch the drawer to the module that has to be upgraded or installed first.
 */
const openRequirement = (req) => {
    if (req.action === 'upgrade') openUpgrade(req.entry)
    else if (req.action === 'install') openInstall(req.entry)
}

/** The typed name must equal the module name before an irreversible install can start. */
const typedMatches = computed(() => drawer.typed.trim() === drawer.name)

const canStart = computed(
    () =>
        Boolean(drawer.result?.can_install) &&
        !drawer.installing &&
        (!irreversible.value || typedMatches.value) &&
        (!thirdParty.value || drawer.thirdPartyConfirmed),
)

/**
 * Load the preflight of the module in the drawer. A late answer of an earlier request is ignored.
 */
const loadPreflight = async () => {
    const token = ++preflightToken
    drawer.loading = true
    drawer.loadError = ''
    drawer.result = null
    drawer.thirdPartyConfirmed = false
    try {
        const result = await getModulePreflight(drawer.name, drawer.version)
        if (token === preflightToken) {
            drawer.result = result
            // the switch starts at the preflight's advice; an older site without advice keeps it on.
            drawer.backup = result?.backup?.recommended !== false
        }
    } catch (error) {
        if (token === preflightToken) drawer.loadError = errorText(error, 'text.modules.install.preflight_failed')
    } finally {
        if (token === preflightToken) drawer.loading = false
    }
}

/**
 * Open the install drawer for a catalog entry and run its preflight.
 */
const openInstall = (entry) => {
    if (!entry?.canInstall || installBlocked.value) return
    Object.assign(drawer, {
        open: true,
        name: entry.name,
        version: entry.latest.version,
        label: entry.label,
        typed: '',
        installing: false,
        submitError: '',
        upgrade: false,
        from: '',
        backup: true,
        publisher: entry.publisher || entry.listing?.publisher || null,
    })
    loadPreflight()
}

/**
 * Open the install drawer for the upgrade offered on an installed runtime module and run its preflight.
 */
const openUpgrade = (entry) => {
    if (!entry?.upgrade_version || installBlocked.value) return
    Object.assign(drawer, {
        open: true,
        name: entry.name,
        version: entry.upgrade_version,
        label: entry.label,
        typed: '',
        installing: false,
        submitError: '',
        upgrade: true,
        from: entry.installed_version || '',
        backup: true,
        publisher: entry.publisher || entry.listing?.publisher || null,
    })
    loadPreflight()
}

const closeDrawer = () => {
    preflightToken++
    drawer.open = false
}

/**
 * Start the install. A second click while the request runs sends nothing; the flag is only released on an error,
 * because on success the drawer closes and the task bar takes over.
 */
const submitInstall = async () => {
    if (drawer.installing || !canStart.value) return
    drawer.installing = true
    drawer.submitError = ''

    const payload = { name: drawer.name, version: drawer.version, backup: Boolean(drawer.backup) }
    if (irreversible.value) {
        payload.confirm_name = drawer.typed.trim()
        payload.confirm_irreversible = true
    }
    if (thirdParty.value) payload.confirm_third_party = true

    try {
        await installModule(payload)
    } catch (error) {
        drawer.submitError = errorText(error, 'text.modules.install.start_failed')
        drawer.installing = false
        return
    }

    iAlert({
        message: t(isUpgrade.value ? 'text.modules.install.started_upgrade' : 'text.modules.install.started', { module: drawer.label }),
        type: 'success',
    })
    closeDrawer()
    tab.value = 'history'
    loadInstalls()
}

// ---- Uninstall drawer --------------------------------------------------------------------------------------------

const uninstall = reactive({
    open: false,
    name: '',
    label: '',
    loading: false,
    loadError: '',
    info: null,
    typed: '',
    dropData: false,
    busy: false,
    submitError: '',
})
let uninstallToken = 0

/** Data tables the module declares; "also delete its data" is only offered when there are some. */
const uninstallTables = computed(() =>
    Array.isArray(uninstall.info?.uninstall_tables) ? uninstall.info.uninstall_tables : [],
)

/** Names of the enabled modules that need this one (they block the uninstall). */
const uninstallDependents = computed(() => (Array.isArray(uninstall.info?.dependents) ? uninstall.info.dependents : []))

/** The typed name must equal the module name; blocked while dependents exist or a request runs. */
const canUninstall = computed(
    () =>
        Boolean(uninstall.info) &&
        !uninstall.busy &&
        uninstallDependents.value.length === 0 &&
        uninstall.typed.trim() === uninstall.name,
)

/**
 * Load what an uninstall of the module in the drawer would do. A late answer of an earlier request is ignored.
 */
const loadUninstallInfo = async () => {
    const token = ++uninstallToken
    uninstall.loading = true
    uninstall.loadError = ''
    uninstall.info = null
    try {
        const info = await getModuleUninstallInfo(uninstall.name)
        if (token === uninstallToken) uninstall.info = info
    } catch (error) {
        if (token === uninstallToken) uninstall.loadError = errorText(error, 'text.modules.install.uninstall.load_failed')
    } finally {
        if (token === uninstallToken) uninstall.loading = false
    }
}

/**
 * Open the uninstall drawer of an installed runtime module (Webmaster only).
 */
const openUninstall = (module) => {
    if (!module?.name || module.source !== 'runtime') return
    Object.assign(uninstall, {
        open: true,
        name: module.name,
        label: module.label,
        typed: '',
        dropData: false,
        busy: false,
        submitError: '',
    })
    loadUninstallInfo()
}

const closeUninstall = () => {
    uninstallToken++
    uninstall.open = false
}

/**
 * Start the uninstall. A second click while the request runs sends nothing; the flag is only released on an error,
 * because on success the drawer closes and the history takes over.
 */
const submitUninstall = async () => {
    if (uninstall.busy || !canUninstall.value) return
    uninstall.busy = true
    uninstall.submitError = ''

    const payload = { confirm_name: uninstall.typed.trim(), drop_data: uninstallTables.value.length > 0 && uninstall.dropData === true }

    try {
        await uninstallModule(uninstall.name, payload)
    } catch (error) {
        uninstall.submitError = errorText(error, 'text.modules.install.uninstall.start_failed', {
            modules: (error?.response?.data?.data?.dependents || uninstallDependents.value).map(labelFor).join(', '),
            tables: (error?.response?.data?.data?.tables || []).join(', '),
            module: uninstall.label,
        })
        uninstall.busy = false
        return
    }

    iAlert({ message: t('text.modules.install.uninstall.started', { module: uninstall.label }), type: 'success' })
    closeUninstall()
    tab.value = 'history'
    loadInstalls()
}

// ---- Upload drawer -----------------------------------------------------------------------------------------------

const zipInput = ref(null)
const sigInput = ref(null)
const UPLOAD_EMPTY_UPGRADE = { upgradeName: '', upgradeFrom: '', upgradeTo: '' }
const upload = reactive({ open: false, zip: null, signature: null, error: '', refused: '', busy: false, needConfirm: false, typed: '', backup: true, thirdParty: null, thirdPartyConfirmed: false, ...UPLOAD_EMPTY_UPGRADE })

const openUpload = () => {
    if (installBlocked.value) return
    Object.assign(upload, { open: true, zip: null, signature: null, error: '', refused: '', busy: false, needConfirm: false, typed: '', backup: true, thirdParty: null, thirdPartyConfirmed: false, ...UPLOAD_EMPTY_UPGRADE })
}

const closeUpload = () => {
    upload.open = false
    Object.assign(upload, UPLOAD_EMPTY_UPGRADE)
}

/**
 * True when dotted numeric version `a` is newer than `b` ("1.10.0" > "1.9.0"); a missing part counts as 0.
 */
const isNewerVersion = (a, b) => {
    const left = String(a).split('.').map((n) => parseInt(n, 10) || 0)
    const right = String(b).split('.').map((n) => parseInt(n, 10) || 0)
    for (let i = 0; i < Math.max(left.length, right.length); i++) {
        const diff = (left[i] || 0) - (right[i] || 0)
        if (diff !== 0) return diff > 0
    }
    return false
}

/**
 * Read the picked signature file (`manifest` = base64 JSON with `name` and `version`) and flag an upgrade when an
 * installed module of that name has an older version. The server preflight stays the authority; any read or
 * parse problem is ignored and the drawer keeps the plain install look.
 */
const detectUpgrade = async (file) => {
    Object.assign(upload, UPLOAD_EMPTY_UPGRADE)
    if (!file) return
    try {
        const envelope = JSON.parse(await file.text())
        const bytes = Uint8Array.from(atob(envelope.manifest), (c) => c.charCodeAt(0))
        const manifest = JSON.parse(new TextDecoder().decode(bytes))
        const installed = (data.value || []).find((m) => m.name === manifest.name && m.version)
        if (installed && manifest.version && isNewerVersion(manifest.version, installed.version) && upload.signature === file) {
            Object.assign(upload, { upgradeName: manifest.name, upgradeFrom: String(installed.version), upgradeTo: String(manifest.version) })
        }
    } catch {
        // Not a readable signature file: nothing to show here, the server checks it.
    }
}

/**
 * Keep a chosen file when it is within the limit; an oversized file is refused here and never sent.
 */
const pickFile = (event, field, maxBytes) => {
    const file = event?.target?.files?.[0] || null
    if (file && file.size > maxBytes) {
        upload[field] = null
        upload.refused = field
        upload.error = t('text.modules.install.upload.too_large', { max: Math.round(maxBytes / (1024 * 1024)) })
        if (event.target) event.target.value = ''
        return
    }
    upload[field] = file
    if (upload.refused === field) {
        upload.refused = ''
        upload.error = ''
    }
}

const onZipChange = (event) => pickFile(event, 'zip', MODULE_PACKAGE_MAX_BYTES)
const onSignatureChange = (event) => {
    pickFile(event, 'signature', MODULE_SIGNATURE_MAX_BYTES)
    return detectUpgrade(upload.signature)
}

const canUpload = computed(
    () =>
        Boolean(upload.zip && upload.signature) &&
        !upload.busy &&
        (!upload.needConfirm || upload.typed.trim() !== '') &&
        (!upload.thirdParty || upload.thirdPartyConfirmed),
)

/**
 * Upload the package and signature. When the package has irreversible migrations the server asks for a typed
 * confirmation; the form then shows the field and the same files are sent again with it.
 */
const submitUpload = async () => {
    if (upload.busy || !canUpload.value) return
    upload.busy = true
    upload.error = ''

    let answer = null
    const form = new FormData()
    form.append('package', upload.zip)
    form.append('signature', upload.signature)
    form.append('backup', upload.backup ? '1' : '0')
    if (upload.needConfirm) {
        form.append('confirm_name', upload.typed.trim())
        form.append('confirm_irreversible', '1')
    }
    if (upload.thirdParty && upload.thirdPartyConfirmed) form.append('confirm_third_party', '1')

    try {
        answer = await uploadModulePackage(form)
    } catch (error) {
        upload.busy = false
        if (moduleErrorCodeOf(error) === 'MODULE_CONFIRMATION_REQUIRED' && !upload.needConfirm) {
            upload.needConfirm = true
            return
        }
        // a partner package asks for its confirmation; the answer carries the publisher and the impact.
        if (moduleErrorCodeOf(error) === 'MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED' && !upload.thirdParty) {
            const data = error?.response?.data?.data
            upload.thirdParty = { publisher: data?.publisher || null, impact: data?.impact || null }
            upload.thirdPartyConfirmed = false
            return
        }
        upload.error = errorText(error, 'text.modules.install.start_failed')
        return
    }

    // The created install row is the authoritative preflight result: it says whether this was an upgrade.
    const row = answer?.data && typeof answer.data === 'object' ? answer.data : answer
    const message =
        row?.kind === 'upgrade'
            ? t('text.modules.install.upload.upgrade_started', {
                  from: row.previous_version || upload.upgradeFrom,
                  to: row.version || upload.upgradeTo,
              })
            : t('text.modules.install.upload.started')
    iAlert({ message, type: 'success' })
    closeUpload()
    tab.value = 'history'
    loadInstalls()
}

// ---- Install history ---------------------------------------------------------------------------------------------

const installRows = computed(() => (Array.isArray(installs.data) ? installs.data : []))

const installColor = (status) =>
    ({ succeeded: 'success', failed: 'error', queued: 'default' })[status] || 'processing'

const installStatusText = (status) => t(`text.modules.install.status.${status}`)

/** `step_message` of a run that waits for the scheduler (no queue worker): `InstallRunDispatcher::STEP_WAITING_FOR_SCHEDULER`. */
const STEP_WAITING_FOR_SCHEDULER = 'Waiting for the scheduled run (at most about a minute).'

/** The run has not started and is picked up by the next scheduled run, so the row says how long that takes. */
const waitingForScheduler = (row) => row.status === 'queued' && !row.started_at && row.step_message === STEP_WAITING_FOR_SCHEDULER

const sourceText = (source) =>
    t(`text.modules.install.history.source_${source === 'upload' ? 'upload' : source === 'local' ? 'local' : 'hub'}`)

/**
 * Warning codes stored on an install row (objects with a `code`, or plain codes).
 */
const warningCodes = (row) =>
    (Array.isArray(row?.warnings) ? row.warnings : [])
        .map((item) => (typeof item === 'string' ? item : item?.code))
        .filter((code) => code && code !== WARN_IRREVERSIBLE && code !== WARN_CUSTOM_CODE && code !== 'MODULE_WARN_RESOURCE_ONLY' && code !== WARN_THIRD_PARTY)
</script>
