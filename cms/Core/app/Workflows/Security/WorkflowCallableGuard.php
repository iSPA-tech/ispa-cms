<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Security;

use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nwidart\Modules\Facades\Module;
use ReflectionClass;

/**
 * Decides which classes, artisan commands and models a workflow step may touch.
 *
 * Deny by default. Every runner that calls a class or reads/writes a model asks this guard before any side effect
 * (container make, dispatch, Artisan::call, query or save). The rules come from config `core.workflow_security`:
 * - `services`: class or namespace prefix (ending in `\`) => list of methods, or `['*']`;
 * - `jobs`, `events`: lists of classes or namespace prefixes;
 * - `commands`: artisan command names (list entry = no arguments, or name => allowed arguments/options);
 * - `job_queues`, `job_connections`: queues and connections an async job step may choose;
 * - `models`: `'registry'` (every concrete model defined under app/, cms/ or projects/, the set ModelSchemaRegistry
 *   offers in the builder) or an explicit list of classes / namespace prefixes;
 * - `deny_models`: classes / namespace prefixes modules add to the deny list.
 *
 * Sensitive models (accounts, credentials, permissions, settings, audit, workflow/agent internals, backups, data
 * scopes) are denied before the `models` rule is read: the Core list in CORE_DENIED_MODELS always applies, whatever
 * config says, and `deny_models` can only add to it. Modules register their own credential, grant, mail and secret
 * models with {@see denyModels()} from their provider's register() (a module that is turned off registers nothing,
 * so the `registry` rule also refuses every model of a module that is not enabled). Matching is case-insensitive.
 *
 * A model step also needs the user the run acts for (the instance's delegate) to be allowed the matching ability on
 * the model ({@see delegateAllows()}): the model's policy decides, or the permission a module registered for a model
 * without a policy ({@see modelPermission()}); no delegate, no policy and no permission all refuse.
 *
 * Class names are matched as strings, so a refused class is never autoloaded by the check itself, except for the
 * model registry check; a command class is only looked up among the commands registered with Artisan.
 *
 * @package Cms\Core\Workflows\Security
 * @since   2026-11-23
 */
class WorkflowCallableGuard
{
    /**
     * Models no workflow step may read or write, whatever the config says. Exact classes, or namespace prefixes
     * ending in `\`.
     *
     * @var list<string>
     */
    public const CORE_DENIED_MODELS = [
        // Accounts and credentials.
        'App\\Models\\User',
        'App\\Models\\PasswordReset',
        'Cms\\Core\\Models\\ApiAccessToken',
        'Laravel\\Sanctum\\PersonalAccessToken',
        'Cms\\Core\\Models\\AuthChallenge',
        'Cms\\Core\\Models\\UserMfaFactor',
        'Cms\\Core\\Models\\UserMfaRecoveryCode',
        'Cms\\SocialAuth\\Models\\SocialAuthIdentity',
        // Roles and permissions.
        'Cms\\Core\\Models\\Role',
        'Cms\\Core\\Models\\Permission',
        // Site and user settings.
        'Cms\\Core\\Models\\Setting',
        'Cms\\Core\\Models\\UserSetting',
        // Module installs, config packages, starter packs.
        'Cms\\Core\\Models\\ModuleInstall',
        'Cms\\Core\\Models\\ConfigPackageRun',
        'Cms\\Core\\Models\\StarterPackApplication',
        // Audit trail and inbound webhooks.
        'Cms\\Core\\Models\\AuditLog',
        'Cms\\Core\\Models\\WebhookReceipt',
        // Workflow and agent internals (projects, steps, instances, approvals, policies, profiles, grants, bindings,
        // schedules), backups and data scopes.
        'Cms\\Core\\Models\\Workflows\\',
        'Cms\\Core\\Models\\Agents\\',
        'Cms\\Core\\Models\\Backup\\',
        'Cms\\Core\\Models\\DataScope\\',
        // Spam block lists (hashed addresses).
        'App\\Models\\SpamEmailBlock',
        'App\\Models\\SpamEmailDomainBlock',
    ];

    /**
     * Classes / namespace prefixes modules denied at boot ({@see denyModels()}).
     *
     * @var array<string, true>
     */
    protected static array $moduleDenied = [];

    /**
     * Permission keys modules registered for models that have no policy ({@see modelPermission()}).
     *
     * @var array<string, string>
     */
    protected static array $modelPermissions = [];

    /**
     * Add models no workflow step may read or write (exact classes, or namespace prefixes ending in `\`).
     *
     * Modules call this from their provider's register() for their credential, grant, mail and secret models. Kept
     * outside config so the order in which providers merge config cannot drop an entry.
     */
    public static function denyModels(string ...$patterns): void
    {
        foreach ($patterns as $pattern) {
            $pattern = ltrim(trim($pattern), '\\');
            if ($pattern !== '') {
                static::$moduleDenied[$pattern] = true;
            }
        }
    }

    /**
     * Let workflow model steps reach a model that has no policy when the run's delegate holds this permission key
     * (for every ability: viewAny, create, update, delete). A model with a policy is always decided by its policy.
     */
    public static function modelPermission(string $modelClass, string $permission): void
    {
        $modelClass = ltrim(trim($modelClass), '\\');
        if ($modelClass !== '' && trim($permission) !== '') {
            static::$modelPermissions[strtolower($modelClass)] = trim($permission);
        }
    }

    /**
     * Why a model step of this run may not do $ability on the model, or null when it may: the model must pass
     * allowsModel() and the run's delegate must be allowed the ability ({@see delegateAllows()}).
     */
    public function modelStepRefusal(WorkflowContext $context, string $ability, Model|string $subject): ?string
    {
        if (!$this->allowsModel(is_string($subject) ? $subject : $subject::class)) {
            return 'model not allowed';
        }

        $delegate = app(WorkflowRunDelegate::class)->for($context);
        if ($delegate === null) {
            return 'the run acts for nobody, so it may not read or write models';
        }

        return $this->delegateAllows($delegate, $ability, $subject)
            ? null
            : "the user this run acts for may not {$ability} this model";
    }

    /**
     * Whether the user the run acts for may do $ability (viewAny, create, update, delete) on the model class or row.
     *
     * Fails closed: no delegate, a Gate error, or a model with neither a policy nor a registered permission refuses.
     */
    public function delegateAllows(?Authenticatable $delegate, string $ability, Model|string $subject): bool
    {
        if ($delegate === null) {
            return false;
        }

        $class = ltrim(is_string($subject) ? trim($subject) : $subject::class, '\\');

        try {
            $gate = app(GateContract::class)->forUser($delegate);

            if ($gate->getPolicyFor($class) === null) {
                $permission = static::$modelPermissions[strtolower($class)] ?? null;

                return $permission !== null && $gate->allows($permission);
            }

            return $gate->allows($ability, $subject);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a `blueprint_callable` step may call this class (and method, for services).
     */
    public function allowsCallable(string $callableType, string $class, ?string $method): bool
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '') {
            return false;
        }

        return match ($callableType) {
            'service' => $this->allowsService($class, $method),
            'job' => $this->matchesAny($class, $this->list('jobs')),
            'event' => $this->matchesAny($class, $this->list('events')),
            'command' => $this->allowsCommand($class),
            default => false,
        };
    }

    /**
     * Whether this app event may start a workflow (`trigger_event`): listed in `trigger_events` (start only) or in
     * `events` (a step may also fire it).
     */
    public function allowsTriggerEvent(string $class): bool
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '') {
            return false;
        }

        return $this->matchesAny($class, [...array_keys($this->triggerEvents()), ...$this->list('events')]);
    }

    /**
     * The `trigger_events` declarations by event class (entries that are not a class name are dropped).
     *
     * @return array<string, array<string, mixed>>
     */
    public function triggerEvents(): array
    {
        $out = [];
        foreach ((array) config('core.workflow_security.trigger_events', []) as $class => $declaration) {
            if (is_string($class) && trim($class) !== '' && !is_numeric($class)) {
                $out[ltrim(trim($class), '\\')] = is_array($declaration) ? $declaration : [];
            }
        }

        return $out;
    }

    /**
     * Whether a step may call this artisan command, given by name (`cache:clear`) or by class.
     */
    public function allowsCommand(string $commandName): bool
    {
        return $this->resolveCommandName($commandName) !== null;
    }

    /**
     * Artisan name of an allowlisted command given by name or class, or null when it is not allowed.
     *
     * A class is only matched against the console commands registered with Artisan (an Illuminate console Command),
     * so an arbitrary string is never autoloaded.
     */
    public function resolveCommandName(string $command): ?string
    {
        $command = ltrim(trim($command), '\\');
        if ($command === '') {
            return null;
        }

        $entries = $this->commandEntries();

        if (!str_contains($command, '\\')) {
            return array_key_exists($command, $entries) ? $command : null;
        }

        $commands = Artisan::all();
        if (!is_iterable($commands)) {
            return null;
        }

        foreach ($commands as $name => $instance) {
            if ($instance instanceof Command && strcasecmp($instance::class, $command) === 0) {
                return array_key_exists((string) $name, $entries) ? (string) $name : null;
            }
        }

        return null;
    }

    /**
     * Arguments and options (`--force`, `name`) a step may pass to an allowlisted command; none unless listed.
     *
     * @return list<string>
     */
    public function allowedCommandOptions(string $commandName): array
    {
        return $this->commandEntries()[$commandName] ?? [];
    }

    /**
     * Whether an async job step may pick this queue connection and queue (null = the job's default).
     */
    public function allowsJobQueue(?string $connection, ?string $queue): bool
    {
        if ($connection !== null && $connection !== '' && !in_array($connection, $this->list('job_connections'), true)) {
            return false;
        }

        return $queue === null || $queue === '' || in_array($queue, $this->list('job_queues'), true);
    }

    /**
     * Whether the model steps may read or write this model class.
     */
    public function allowsModel(string $modelClass): bool
    {
        $modelClass = ltrim(trim($modelClass), '\\');
        if ($modelClass === '') {
            return false;
        }

        // Deny list first: no allow rule can open a sensitive model.
        if ($this->isDeniedModel($modelClass)) {
            return false;
        }

        $rule = config('core.workflow_security.models', 'registry');

        if (is_array($rule)) {
            return $this->matchesAny($modelClass, array_values(array_filter($rule, 'is_string')))
                && class_exists($modelClass)
                && is_subclass_of($modelClass, Model::class);
        }

        if ($rule !== 'registry') {
            return false;
        }

        return $this->isRegistryModel($modelClass);
    }

    /**
     * Whether the model is on the Core deny list or on a module's `deny_models` entries (case-insensitive).
     */
    public function isDeniedModel(string $modelClass): bool
    {
        $modelClass = ltrim(trim($modelClass), '\\');

        return $this->matchesAny($modelClass, [
            ...self::CORE_DENIED_MODELS,
            ...array_keys(static::$moduleDenied),
            ...$this->list('deny_models'),
        ]);
    }

    /**
     * Service rule: the class (or a namespace prefix) lists the method, or `*`.
     */
    protected function allowsService(string $class, ?string $method): bool
    {
        if (!is_string($method) || $method === '' || str_starts_with($method, '__')) {
            return false;
        }

        $services = config('core.workflow_security.services', []);
        if (!is_array($services)) {
            return false;
        }

        foreach ($services as $pattern => $methods) {
            if (!is_string($pattern) || !$this->matches($class, $pattern)) {
                continue;
            }

            $methods = is_array($methods) ? $methods : [$methods];
            if (in_array('*', $methods, true) || in_array($method, $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A concrete Eloquent model whose file lives under app/, cms/ or projects/ (what ModelSchemaRegistry lists); a
     * model under cms/<Module>/ only while that module is enabled (a turned-off module registers no deny entries).
     */
    protected function isRegistryModel(string $modelClass): bool
    {
        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        $ref = new ReflectionClass($modelClass);
        if ($ref->isAbstract()) {
            return false;
        }

        $file = $ref->getFileName();
        if (!is_string($file) || $file === '') {
            return false;
        }

        $file = realpath($file) ?: $file;
        $cms = rtrim(realpath(base_path('cms')) ?: base_path('cms'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $cms)) {
            return $this->moduleEnabled(strstr(substr($file, strlen($cms)), DIRECTORY_SEPARATOR, true) ?: '');
        }

        foreach ([app_path(), base_path('projects')] as $root) {
            $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the module in cms/<$name> is installed and enabled (fails closed on any lookup error).
     */
    protected function moduleEnabled(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        try {
            $module = Module::find($name);

            return $module !== null && $module->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Allowlisted commands as name => allowed arguments/options. `'cache:clear'` allows no arguments;
     * `'cache:clear' => ['--tags']` allows the listed ones.
     *
     * @return array<string, list<string>>
     */
    protected function commandEntries(): array
    {
        $value = config('core.workflow_security.commands', []);
        if (!is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $key => $entry) {
            if (is_int($key) && is_string($entry) && $entry !== '') {
                $entries[$entry] ??= [];
            } elseif (is_string($key) && $key !== '') {
                $entries[$key] = array_values(array_filter((array) $entry, 'is_string'));
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function list(string $key): array
    {
        $value = config('core.workflow_security.' . $key, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param list<string> $patterns
     */
    protected function matchesAny(string $class, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($class, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact class name, or namespace prefix when the pattern ends in `\`.
     */
    protected function matches(string $class, string $pattern): bool
    {
        $pattern = ltrim(trim($pattern), '\\');
        if ($pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '\\')) {
            return str_starts_with(strtolower($class), strtolower($pattern));
        }

        return strcasecmp($class, $pattern) === 0;
    }
}
