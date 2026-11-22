<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;
use Cms\Core\Constants\CoreTableConstant;

class Tables
{
    public const FULLTEXT_SEARCH = '%s_search_iSPA';

    public const AUTH_CHALLENGES = CoreTableConstant::AUTH_CHALLENGES;
    public const API_LOGS = CoreTableConstant::API_LOGS;
    public const AUDIT_LOGS = 'audit_logs';
    public const BANNERS = 'banners';
    public const BOOKMARK = 'bookmarks';
    public const CALENDARS = 'calendars';
    public const CATEGORIES = CoreTableConstant::CATEGORIES;
    public const CATEGORY_TARGET = CoreTableConstant::CATEGORY_TARGET;
    public const COMMENTS = 'comments';
    public const CONTACTS = 'contacts';
    public const CONTENTS = 'contents';
    public const EMAILS = CoreTableConstant::EMAILS;
    public const EMAIL_LOGS = CoreTableConstant::EMAIL_LOGS;
    public const EMAIL_TEMPLATES = CoreTableConstant::EMAIL_TEMPLATES;
    public const FIELDS = CoreTableConstant::FIELDS;
    public const GALLERIES = 'galleries';
    public const LANGUAGES = CoreTableConstant::LANGUAGES;
    public const MENUS = CoreTableConstant::MENUS;
    public const META_ATTRIBUTES = CoreTableConstant::META_ATTRIBUTES;
    public const META_ATTRIBUTE_VALUE_PIVOT = CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT;
    public const META_CLASSES = CoreTableConstant::META_CLASSES;
    public const META_CLASS_ATTRIBUTE_PIVOT = CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT;
    public const META_DATA = CoreTableConstant::META_DATA;
    public const META_VALUES_BIG_NUM = CoreTableConstant::META_VALUES_BIG_NUM;
    public const META_VALUES_BOOLEAN = CoreTableConstant::META_VALUES_BOOLEAN;
    public const META_VALUES_DATE = CoreTableConstant::META_VALUES_DATE;
    public const META_VALUES_RANGE = CoreTableConstant::META_VALUES_RANGE;
    public const META_VALUES_STRING = CoreTableConstant::META_VALUES_STRING;
    public const META_VALUES_TEXT = CoreTableConstant::META_VALUES_TEXT;
    public const META_VALUES_TINY_NUM = CoreTableConstant::META_VALUES_TINY_NUM;
    // Literal names for the tables of optional modules (Ecommerce, Product): an install without those modules must still
    // read every constant here (getConstants() reflects them all).
    public const ORDERS = 'orders';
    public const PASSWORD_RESETS = CoreTableConstant::PASSWORD_RESETS;
    public const PERMISSIONS = CoreTableConstant::PERMISSIONS;
    public const PLACES = 'places';
    public const PLACE_ITEMS = 'place_items';
    public const POSTS = 'posts';
    public const PROVINCES = 'provinces';
    public const ROLES = CoreTableConstant::ROLES;
    public const SCHEDULER = CoreTableConstant::SCHEDULER;
    public const SEOS = 'seos';
    public const SLUGS = 'slugs';
    public const SPAM_EMAIL_BLOCKS = 'spam_email_blocks';
    public const SPAM_EMAIL_DOMAIN_BLOCKS = 'spam_email_domain_blocks';
    public const STORAGES = CoreTableConstant::STORAGES;
    public const TAGS = 'tags';
    public const TAGS_DATA = 'tags_data';
    public const TRACKS = 'tracks';
    public const TRACKING_DAILY = 'tracking_daily';
    public const TRACKING_COUNTER_FLUSH_BATCHES = 'tracking_counter_flush_batches';
    public const TRACKING_COUNTER_RUNTIME = 'tracking_counter_runtime';
    public const TRACKING_EVENT_RECEIPTS = 'tracking_event_receipts';
    public const TRACKING_GENERATION_RUNS = 'tracking_generation_runs';
    public const TRACKING_PRUNE_RUNS = 'tracking_prune_runs';
    public const TRACKING_RUNTIME_STATE = 'tracking_runtime_state';
    public const TRACKING_SITE_TOTALS = 'tracking_site_totals';
    public const TRACKING_TARGET_CATALOG = 'tracking_target_catalog';
    public const TRACKING_TARGET_DAILY = 'tracking_target_daily_v2';
    public const TRACKING_TARGET_TOTALS = 'tracking_target_totals';
    public const TRACKING_TOP_DAILY = 'tracking_top_daily';
    public const TRACKING_TOP = 'tracking_top';
    public const TRANSLATES = CoreTableConstant::TRANSLATES;
    public const UNIT_OF_MEASURE = 'unit_of_measures'; // Product module table, see ORDERS
    public const UPLOADS = CoreTableConstant::UPLOADS;
    public const USERS = CoreTableConstant::USERS;
    public const USER_MFA_FACTORS = CoreTableConstant::USER_MFA_FACTORS;
    public const USER_MFA_RECOVERY_CODES = CoreTableConstant::USER_MFA_RECOVERY_CODES;
    public const USERS_META = CoreTableConstant::USERS_META;
    public const VECTOR_EMBEDDINGS = CoreTableConstant::VECTOR_EMBEDDINGS;
    public const AGENT_PROFILE = CoreTableConstant::AGENT_PROFILE;
    public const AGENT_TOOL_DEFINITION = CoreTableConstant::AGENT_TOOL_DEFINITION;
    public const AGENT_TOOL_BINDING = CoreTableConstant::AGENT_TOOL_BINDING;
    public const AGENT_PROFILE_TOOL = CoreTableConstant::AGENT_PROFILE_TOOL;
    public const AGENT_RUN = CoreTableConstant::AGENT_RUN;
    public const AGENT_RUN_STEP = CoreTableConstant::AGENT_RUN_STEP;
    public const AGENT_TOOL_CALL = CoreTableConstant::AGENT_TOOL_CALL;
    public const AGENT_ARTIFACT = CoreTableConstant::AGENT_ARTIFACT;
    public const AGENT_APPROVAL = CoreTableConstant::AGENT_APPROVAL;
    public const AGENT_MEMORY = CoreTableConstant::AGENT_MEMORY;
    public const AGENT_POLICY = CoreTableConstant::AGENT_POLICY;
    public const AGENT_SCHEDULE = CoreTableConstant::AGENT_SCHEDULE;

    public const WORKFLOW_PROJECT = CoreTableConstant::WORKFLOW_PROJECT;
    public const WORKFLOW_STEP = CoreTableConstant::WORKFLOW_STEP;
    public const WORKFLOW_CONNECTION = CoreTableConstant::WORKFLOW_CONNECTION;
    public const WORKFLOW_INSTANCE = CoreTableConstant::WORKFLOW_INSTANCE;
    public const WORKFLOW_INSTANCE_STEP = CoreTableConstant::WORKFLOW_INSTANCE_STEP;
    public const WIDGET = CoreTableConstant::WIDGET;
    public const WIDGET_INPUT = CoreTableConstant::WIDGET_INPUT;
    public const WIDGET_THEME = CoreTableConstant::WIDGET_THEME;

    //  Stored Procedures
    public const GET_DAILY_TRACKING = 'get_daily_tracking';
    public const GET_TOTAL_COUNTING_REPORT = 'get_total_counting_report';
    public const GET_TOTAL_REPORT_WEB = 'get_total_report_web';
    public const GET_BEST_VIEW_BY_DATE_RANGE = 'get_best_view_by_date_range';

    /**
     * Get all constants defined in the class
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-23, 23:25 ICT
     */
    public static function getConstants(): array
    {
        // Get the class name
        $className = static::class;

        // Create a ReflectionClass instance for the class
        // Get all constants defined in the class
        return (new ReflectionClass($className))->getConstants();
    }
}
