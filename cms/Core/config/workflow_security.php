<?php

/**
 * What workflow steps may call. Read by Cms\Core\Workflows\Security\WorkflowCallableGuard.
 *
 * Deny by default: a `blueprint_callable` step runs only a service method, job, event or artisan command listed here,
 * and the model steps (`retrieve`, `create_object`, `change`, `clone_object`, `delete_object`, `commit_objects`)
 * touch only the models allowed by `models`. A refused step fails with `<step type>: class not allowed` or
 * `<step type>: model not allowed` before any side effect.
 *
 * Class entries are either an exact class name or a namespace prefix ending in `\` (for example `Cms\Blog\Services\`).
 * Modules may add entries at boot (config()->push / config()->set) for the callables they ship for workflows.
 */
return [
    /**
     * Service classes => allowed methods. Use ['*'] to allow every public method of the class (or namespace).
     *
     * Example: \Cms\Blog\Services\PostSeoService::class => ['refresh'],
     */
    'services' => [],

    /** Job classes (or namespace prefixes) a step may dispatch. */
    'jobs' => [],

    /**
     * Event classes (or namespace prefixes) a step may fire. An event listed here may also start a workflow
     * (`trigger_event`), as before `trigger_events` existed.
     */
    'events' => [],

    /**
     * App events that may START a workflow (`trigger_event`) but that no step may fire: event class => declaration.
     * Read by WorkflowTriggerBootstrap (which events are listened to) and AppEventTriggerListener (asked again when
     * one fires). The event's public properties are the run's `event.payload`, so an event listed here must carry
     * only the data a workflow may see.
     *
     * Declaration: `['schema' => <JSON schema of the payload>]`. A template copies the schema into its start node's
     * `schema` so the designer's data picker lists the payload fields (`event.payload.<field>`).
     *
     * Modules add their own events from their provider's register(), before Core boots (Core reads the list once
     * per boot): config()->set('core.workflow_security.trigger_events.' . MyEvent::class, [...]).
     */
    'trigger_events' => [],

    /**
     * Artisan commands a step may call, by name. A list entry (`'cache:clear'`) takes no arguments; a keyed entry lists
     * the arguments and options a step may pass (`'cache:clear' => ['--tags']`). A step may name the command class
     * instead; it is matched against the commands registered with Artisan.
     */
    'commands' => [],

    /** Queues and queue connections an async job step may choose; leave them out to use the job's defaults. */
    'job_queues' => [],
    'job_connections' => [],

    /**
     * Models the model steps may read or write.
     *
     * 'registry' = every concrete Eloquent model ModelSchemaRegistry offers in the builder (classes defined under
     * app/, cms/ or projects/); vendor models are refused. Or an explicit list of classes / namespace prefixes.
     */
    'models' => 'registry',

    /**
     * Extra models no step may touch, checked before `models`: exact classes or namespace prefixes ending in `\`.
     *
     * The Core sensitive models (users, password resets, roles, permissions, API tokens, auth challenges, MFA factors
     * and recovery codes, settings, module installs, config package runs, audit log, webhook receipts, every workflow,
     * agent, backup and data-scope model) are always denied by WorkflowCallableGuard::CORE_DENIED_MODELS; this list
     * only adds to them. Modules register their own credential, grant, mail and secret models with
     * WorkflowCallableGuard::denyModels() from their provider's register(); with `registry`, every model of a module
     * that is not enabled is refused as well. Every model step also needs the run's delegate to be allowed the
     * matching ability (viewAny / create / update / delete) by the model's policy, or by the permission a module
     * registered with WorkflowCallableGuard::modelPermission() for a model without a policy.
     */
    'deny_models' => [],

    /**
     * Hosts the fetch nodes (http_request, rss_read, sitemap_read, media_import) may call, read by
     * Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist. Empty = no outbound calls.
     *
     * Entries: `example.com` (that host only), `*.example.com` (any subdomain, not the apex), optional `:port`
     * (`api.example.com:8443`); without a port only 80 and 443 are allowed. The Webmaster adds more in the admin
     * (setting `workflow.http_hosts`); both lists apply. Allowed hosts still never reach private, loopback,
     * link-local, metadata or reserved addresses (SafeHttpFetcher checks DNS on every hop).
     */
    'http_hosts' => [],
];
