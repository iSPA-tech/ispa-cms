<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Illuminate\Support\Str;

/**
 * The plain-language summary of a workflow template, derived from its package and setup questions (never typed), and
 * its rendering in the admin's language.
 *
 * derive() is pure: steps in run order (from the start step along the connections, then any unreached step by position)
 * with their names and safety classes, the package manifest's safety classes, outbound hosts and credential kinds,
 * whether a person decides somewhere (an approval step, or a step whose approval is required by default), and the
 * setup questions (`kind`, and for a step question `step_code`, `field`, `step_name`).
 *
 * lines() renders `{does, asks, may, tags}` with the translation keys of the "Workflow hub library (phase C)" block.
 */
final class WorkflowBundleSummary
{
    /** Card tags in display order: tag key => safety class or flag that shows it. */
    public const TAGS = ['ai_spend' => 'ai_spend', 'needs_approval' => 'needs_approval', 'publish' => 'publish', 'external_call' => 'external_call'];

    /**
     * @param array<string, mixed>       $package   workflow package v1
     * @param list<array<string, mixed>> $questions WorkflowSetupService::describe() questions of the source project
     * @return array<string, mixed>
     */
    public static function derive(array $package, array $questions): array
    {
        $row = (array) ($package['workflow'] ?? []);
        $manifest = (array) ($package['manifest'] ?? []);
        $safetyByCode = [];
        $approvalRequired = false;
        foreach ((array) ($manifest['steps'] ?? []) as $step) {
            if (is_array($step)) {
                $safetyByCode[(string) ($step['step_code'] ?? '')] = array_values((array) ($step['safety'] ?? []));
                $approvalRequired = $approvalRequired || ($step['approval_default'] ?? null) === 'required';
            }
        }

        $steps = [];
        $names = [];
        $hasApprovalStep = false;
        foreach (self::ordered($row) as $step) {
            $code = (string) ($step['step_code'] ?? '');
            $type = (string) ($step['step_type'] ?? '');
            $name = is_string($step['name'] ?? null) && trim($step['name']) !== '' ? trim($step['name']) : $code;
            $names[$code] = $name;
            $hasApprovalStep = $hasApprovalStep || $type === 'approval';
            $steps[] = ['step_code' => $code, 'name' => $name, 'step_type' => $type, 'safety' => $safetyByCode[$code] ?? []];
        }

        $setup = [];
        foreach ($questions as $q) {
            if (!is_array($q) || !is_string($q['kind'] ?? null)) {
                continue;
            }
            $item = ['kind' => $q['kind']];
            if (is_string($q['step_code'] ?? null)) {
                $item['step_code'] = $q['step_code'];
                $item['field'] = (string) ($q['field'] ?? '');
                $item['step_name'] = $names[$q['step_code']] ?? $q['step_code'];
            }
            $setup[] = $item;
        }

        return [
            'steps' => $steps,
            'safety_classes' => array_values((array) ($manifest['safety_classes'] ?? [])),
            'outbound_hosts' => array_values((array) ($manifest['outbound_hosts'] ?? [])),
            'credential_kinds' => array_values((array) ($manifest['credential_kinds'] ?? [])),
            'needs_approval' => $hasApprovalStep || $approvalRequired,
            'setup_questions' => $setup,
        ];
    }

    /**
     * The summary in the admin's language: `does` (step names in order), `asks` (setup question labels), `may` (what the
     * template is allowed to do) and `tags` (`[{key, label}]` for the library card).
     *
     * @param array<string, mixed> $summary
     * @return array{does: list<string>, asks: list<string>, may: list<string>, tags: list<array{key: string, label: string}>}
     */
    public static function lines(array $summary): array
    {
        $classes = array_values(array_filter((array) ($summary['safety_classes'] ?? []), 'is_string'));
        $hosts = array_values(array_filter((array) ($summary['outbound_hosts'] ?? []), 'is_string'));

        $does = [];
        foreach ((array) ($summary['steps'] ?? []) as $step) {
            if (is_array($step) && is_string($step['name'] ?? null) && $step['name'] !== '') {
                $does[] = $step['name'];
            }
        }

        $asks = [];
        foreach ((array) ($summary['setup_questions'] ?? []) as $q) {
            if (!is_array($q) || !is_string($q['kind'] ?? null)) {
                continue;
            }
            $field = isset($q['field']) ? self::text('text.workflow.setup.field.' . $q['field'], Str::headline((string) $q['field'])) : '';
            $asks[] = self::text('text.workflow.library.ask.' . $q['kind'], self::ASK_FALLBACK[$q['kind']] ?? $q['kind'], [
                'step' => (string) ($q['step_name'] ?? ''), 'field' => $field,
            ]);
        }

        $may = [];
        if (in_array('ai_spend', $classes, true)) {
            $may[] = self::text('text.workflow.library.may.ai_spend', 'Uses AI (counted in the site’s AI budget)');
        }
        if (in_array('publish', $classes, true)) {
            $may[] = self::text('text.workflow.library.may.publish', 'Publishes on the website, only after a person approves');
        }
        if (in_array('internal_write', $classes, true)) {
            $may[] = self::text('text.workflow.library.may.internal_write', 'Changes data on this website');
        }
        if (in_array('external_call', $classes, true)) {
            $named = array_values(array_filter($hosts, static fn (string $h): bool => $h !== '(expression)'));
            $may[] = $named === []
                ? self::text('text.workflow.library.may.external_call', 'Sends data to other websites')
                : self::text('text.workflow.library.may.external_hosts', 'Sends data to: :hosts', ['hosts' => implode(', ', $named)]);
        } else {
            $may[] = self::text('text.workflow.library.may.no_external', 'Sends no data to other websites');
        }
        if (in_array('unknown', $classes, true)) {
            $may[] = self::text('text.workflow.library.may.unknown', 'Has a step this website does not know yet');
        }

        $tags = [];
        foreach (self::TAGS as $tag => $source) {
            $on = $source === 'needs_approval' ? ($summary['needs_approval'] ?? false) === true : in_array($source, $classes, true);
            if ($on) {
                $tags[] = ['key' => $tag, 'label' => self::text('text.workflow.library.tag.' . $tag, self::TAG_FALLBACK[$tag])];
            }
        }

        return ['does' => $does, 'asks' => $asks, 'may' => $may, 'tags' => $tags];
    }

    private const ASK_FALLBACK = [
        'reviewers' => 'Who reviews at “:step”',
        'notify_channel' => 'Who is told at “:step”',
        'credential' => 'Which connected account “:step” uses',
        'required_field' => '“:step” needs: :field',
        'schedule' => 'When it runs',
        'choice' => 'Choose: :step',
    ];

    private const TAG_FALLBACK = [
        'ai_spend' => 'Uses AI credit',
        'needs_approval' => 'Waits for a reviewer',
        'publish' => 'Publishes on the web',
        'external_call' => 'Sends data out',
    ];

    /**
     * Steps from the start step along the connections (breadth first, connection order), then unreached steps by position.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $row): array
    {
        $steps = array_values(array_filter((array) ($row['steps'] ?? []), 'is_array'));
        usort($steps, static fn (array $a, array $b): int => [(int) ($a['position_x'] ?? 0), (int) ($a['position_y'] ?? 0), (string) ($a['step_code'] ?? '')]
            <=> [(int) ($b['position_x'] ?? 0), (int) ($b['position_y'] ?? 0), (string) ($b['step_code'] ?? '')]);
        $byCode = [];
        foreach ($steps as $step) {
            $byCode[(string) ($step['step_code'] ?? '')] = $step;
        }
        $next = [];
        foreach ((array) ($row['connections'] ?? []) as $c) {
            if (is_array($c) && is_string($c['from'] ?? null) && is_string($c['to'] ?? null)) {
                $next[$c['from']][] = $c['to'];
            }
        }

        $queue = array_keys(array_filter($byCode, static fn (array $s): bool => ($s['is_start'] ?? false) === true));
        $seen = [];
        $out = [];
        while ($queue !== []) {
            $code = (string) array_shift($queue);
            if (isset($seen[$code]) || !isset($byCode[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $byCode[$code];
            foreach ($next[$code] ?? [] as $to) {
                $queue[] = $to;
            }
        }
        foreach ($byCode as $code => $step) {
            if (!isset($seen[$code])) {
                $out[] = $step;
            }
        }

        return $out;
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, string $fallback, array $replace = []): string
    {
        $text = __($key, $replace);
        if (is_string($text) && $text !== $key) {
            return $text;
        }
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = $value;
        }

        return strtr($fallback, $pairs);
    }
}
