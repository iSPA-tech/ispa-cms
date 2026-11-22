<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Constants;

/**
 * Core module table name constants for Eloquent models and pivots.
 *
 * Centralizes all table name strings used by Core module models,
 * migrations, and queries — including authentication, categories,
 * menus, meta-attributes, roles/permissions, workflows, agents,
 * and widget subsystems.
 *
 * @package Cms\Core\Constants
 */
class CoreTableConstant
{
    // --- Authentication ---

    /** MFA and login verification challenge records */
    public const AUTH_CHALLENGES = 'auth_challenges';

    // --- Agent subsystem ---

    /** Agent execution approval requests and decisions */
    public const AGENT_APPROVAL = 'agent_approvals';

    /** Output files and data produced by agent runs */
    public const AGENT_ARTIFACT = 'agent_artifacts';

    /** Persistent memory entries retained across agent sessions */
    public const AGENT_MEMORY = 'agent_memories';

    /** Governance policies controlling agent behavior and scope */
    public const AGENT_POLICY = 'agent_policies';

    /** Registered agent identity and configuration profiles */
    public const AGENT_PROFILE = 'agent_profiles';

    /** Tool assignments linked to agent profiles */
    public const AGENT_PROFILE_TOOL = 'agent_profile_tools';

    /** Individual agent execution records */
    public const AGENT_RUN = 'agent_runs';

    /** Step-by-step trace of actions within an agent run */
    public const AGENT_RUN_STEP = 'agent_run_steps';

    /** Cron-based schedules triggering agent runs */
    public const AGENT_SCHEDULE = 'agent_schedules';

    /** Runtime bindings between agents and tool implementations */
    public const AGENT_TOOL_BINDING = 'agent_tool_bindings';

    /** Audit log of tool invocations made during agent runs */
    public const AGENT_TOOL_CALL = 'agent_tool_calls';

    /** Registered tool definitions available to agents */
    public const AGENT_TOOL_DEFINITION = 'agent_tool_definitions';

    /** Structured AI extraction runs and cached provider responses */
    public const AI_EXTRACTION_RUNS = 'core_ai_extraction_runs';

    /** Daily LLM usage ledger (tokens, estimated cost) per provider, model and task */
    public const AI_USAGE_DAILY = 'core_ai_usage_daily';

    /** Per-call LLM trace log (no prompt or response content), pruned by retention */
    public const AI_CALL_LOGS = 'core_ai_call_logs';

    /** Generic record of long-running admin work shown in the admin header task bar */
    public const BACKGROUND_TASKS = 'core_background_tasks';

    /** Internal notes (with @mentions) attached to any registered admin record type */
    public const RECORD_NOTES = 'core_record_notes';

    /** Per-user saved filter states of an admin list, optionally shared; capped at 20 per user per list */
    public const SAVED_VIEWS = 'core_saved_views';

    /** One config package export, dry run or apply: metadata row plus a compressed, prunable payload */
    public const CONFIG_PACKAGE_RUNS = 'core_config_package_runs';

    /** Which starter pack and version was applied to a site, by whom, and what each step did */
    public const STARTER_PACK_APPLICATIONS = 'core_starter_pack_applications';

    /** Shared webhook inbox: one row per provider and event id, kept for retry and replay */
    public const WEBHOOK_RECEIPTS = 'core_webhook_receipts';

    /** Shared source targets used to enrich existing domain rows */
    public const ENRICHMENT_SOURCES = 'enrichment_sources';

    // --- Core entities ---

    /** Inbound API request/response audit log */
    public const API_LOGS = 'api_logs';

    /** General audit trail for data mutations */
    public const AUDIT_LOGS = 'audit_logs';

    /** Backup storage destinations (S3, local, SFTP, WebDAV, cloud drives) */
    public const BACKUP_DESTINATIONS = 'core_backup_destinations';

    /** Database backup runs */
    public const BACKUPS = 'core_backups';

    /** Per-destination copies of a backup (and destination probes) */
    public const BACKUP_COPIES = 'core_backup_copies';

    /** Hierarchical taxonomy categories */
    public const CATEGORIES = 'categories';

    /** Polymorphic pivot linking categories to categorizable entities */
    public const CATEGORY_TARGET = 'category_target';

    /** Email message records */
    public const EMAILS = 'emails';

    /** Delivery and bounce log for sent emails */
    public const EMAIL_LOGS = 'email_logs';

    /** Reusable email template definitions */
    public const EMAIL_TEMPLATES = 'email_templates';

    /** Dynamic enumeration values (select-list options) */
    public const ENUMERATES = 'enumerates';

    /** Grouping types for dynamic enumerations */
    public const ENUMERATE_TYPES = 'enumerates_types';

    /** Custom field definitions for extensible forms */
    public const FIELDS = 'fields';

    /** Option items belonging to a custom field */
    public const FIELD_ITEMS = 'field_items';

    /** Supported locale/language entries */
    public const LANGUAGES = 'languages';

    /** Navigation menu group definitions */
    public const MENUS = 'menus';

    /** Individual menu item entries within a menu group */
    public const MENU_ITEMS = 'menu_items';

    // --- Meta attribute subsystem ---

    /** EAV attribute definitions */
    public const META_ATTRIBUTES = 'meta_attributes';

    /** Pivot linking meta attributes to their values */
    public const META_ATTRIBUTE_VALUE_PIVOT = 'meta_attribute_value_pivot';

    /** Pivot linking meta classes to assigned attributes */
    public const META_CLASS_ATTRIBUTE_PIVOT = 'meta_class_attribute_pivot';

    /** EAV entity class definitions */
    public const META_CLASSES = 'meta_classes';

    /** Generic metadata key-value store */
    public const META_DATA = 'meta_data';

    /** EAV boolean-typed attribute values */
    public const META_VALUES_BOOLEAN = 'meta_values_boolean';

    /** EAV short-string attribute values */
    public const META_VALUES_STRING = 'meta_values_string';

    /** EAV long-text attribute values */
    public const META_VALUES_TEXT = 'meta_values_text';

    /** EAV date/datetime attribute values */
    public const META_VALUES_DATE = 'meta_values_date';

    /** EAV small-integer attribute values */
    public const META_VALUES_TINY_NUM = 'meta_values_tiny_num';

    /** EAV large-number (decimal/bigint) attribute values */
    public const META_VALUES_BIG_NUM = 'meta_values_big_num';

    /** EAV canonical numeric ranges */
    public const META_VALUES_RANGE = 'meta_values_range';

    // --- Roles & permissions ---

    /** Password reset token records */
    public const PASSWORD_RESETS = 'password_resets';

    /** RBAC role definitions */
    public const ROLES = 'roles';

    /** Pivot linking users to roles */
    public const ROLE_USER = 'role_user';

    /** Shared tags (post tags and user tags, told apart by `type`) */
    public const TAGS = 'tags';

    /** Polymorphic pivot linking tags to posts, users... (`source` for user tags) */
    public const TAGS_DATA = 'tags_data';

    /** Granular permission definitions */
    public const PERMISSIONS = 'permissions';

    /** Pivot linking permissions to roles */
    public const PERMISSION_ROLE = 'permission_role';

    /** Per-role data-scope level for a permission key */
    public const DATA_SCOPE_GRANTS = 'data_scope_grants';

    /** Data-scope node assignments for users and roles */
    public const DATA_SCOPE_ASSIGNMENTS = 'data_scope_assignments';

    // --- Core entities (continued) ---

    /** Scheduled task entries */
    public const SCHEDULER = 'schedulers';

    /** Translation string records for multilingual content */
    public const TRANSLATES = 'translates';

    /** Per-site admin schema overlays (hidden fields, order and label overrides) keyed by resource */
    public const RESOURCE_OVERLAYS = 'resource_overlays';

    /** System and module configuration key-value settings */
    public const SETTINGS = 'settings';

    /** Fingerprints for expensive static data seeders */
    public const SEED_FINGERPRINTS = 'core_seed_fingerprints';

    /** Storage disk/provider configuration records */
    public const STORAGES = 'storages';

    /** Polymorphic pivot linking uploads to owning entities */
    public const UPLOAD_TARGET_PIVOT = 'upload_target_pivot';

    /** Uploaded file metadata records */
    public const UPLOADS = 'uploads';

    /** User account records */
    public const USERS = 'users';

    /** Registered MFA factors (TOTP, SMS, etc.) per user */
    public const USER_MFA_FACTORS = 'user_mfa_factors';

    /** One-time MFA recovery codes per user */
    public const USER_MFA_RECOVERY_CODES = 'user_mfa_recovery_codes';

    /** Extended user profile metadata */
    public const USERS_META = 'user_meta';

    /** Per-user preference settings */
    public const USERS_SETTINGS = 'user_settings';

    /**
     * Vector embeddings for AI semantic search.
     *
     * @deprecated The table, its migration and model belong to the client module that uses them since 2026-11-21
     *             (no Core code reads this constant). Kept because removing a constant from this class is a breaking
     *             change of the Content contract.
     */
    public const VECTOR_EMBEDDINGS = 'vector_embeddings';

    /** Source cluster records for reusable published-data tracking */
    public const PUBLISHED_DATA_SOURCE_CLUSTERS = 'core_published_data_source_clusters';

    /** Candidate entries tracked during published-data ingestion */
    public const PUBLISHED_DATA_CANDIDATES = 'core_published_data_candidates';

    /** Embedding vectors captured for published-data similarity checks */
    public const PUBLISHED_DATA_EMBEDDINGS = 'core_published_data_embeddings';

    // --- Widget subsystem ---

    /** Widget instance definitions */
    public const WIDGET = 'widgets';

    /** Visual themes assigned to widgets */
    public const WIDGET_THEME = 'widget_themes';

    /** User-configurable input fields within a widget */
    public const WIDGET_INPUT = 'widget_inputs';

    // --- Workflow subsystem ---

    /** Workflow blueprint definitions */
    public const WORKFLOW = 'workflows';

    /** Connections linking workflow steps */
    public const WORKFLOW_CONNECTION = 'workflow_connections';

    /** Running workflow execution instances */
    public const WORKFLOW_INSTANCE = 'workflow_instances';

    /** Step state within a running workflow instance */
    public const WORKFLOW_INSTANCE_STEP = 'workflow_instance_steps';

    /** Project containers grouping related workflows */
    public const WORKFLOW_PROJECT = 'workflow_projects';

    /** Individual step definitions within a workflow blueprint */
    public const WORKFLOW_STEP = 'workflow_steps';

    /** Comments on any commentable target */
    public const COMMENTS = 'comments';

    /** SEO rows of any target */
    public const SEOS = 'seos';

    /** URL slugs of any target */
    public const SLUGS = 'slugs';

    /** Full-text index name pattern; `%s` is the table name */
    public const FULLTEXT_SEARCH = '%s_search_iSPA';
}
