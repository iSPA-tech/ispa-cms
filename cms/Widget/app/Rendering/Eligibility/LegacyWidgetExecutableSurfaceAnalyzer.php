<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

/**
 * Module-owned analyzer producing the canonical legacy Widget/Theme
 * executable-surface fingerprint (plan Chunk 1 Task 9c).
 *
 * Pure, persistence-free, and side-effect-free: every input is a plain
 * scalar/array value supplied by the caller (raw Widget JavaScript, raw
 * Theme content/CSS, the already-resolved canonical schema, policy
 * version, trust lane, applicability, and already-generated artifact
 * bytes). This class performs no database or filesystem I/O of its own --
 * Task 15a/15b own materializing and persisting Manifest/attestation rows
 * once Task 13a creates storage.
 *
 * docs/evidence/widget-site-layout-preflight.md §1 records the concrete
 * facts this fingerprint must be sensitive to: Theme `content` reaches
 * `Blade::render()` verbatim (raw `@php`, `{!! !!}`, any Blade directive,
 * `<script>` tags, inline `on*=` handlers all execute -- trusted-author
 * model, nothing is sanitized); Widget JavaScript is appended into the
 * SAME generated artifact inside `@push('script') ... @endpush`; Theme CSS
 * is inlined as a leading `<style>...</style>` block; and the placeholder
 * grammar rewritten before Blade execution is `{query:type.name ...}`,
 * `{list:name ...}`, `{image ...}`, `{upload ...}`, `{iSeo:key}`, and plain
 * `{inputName}` substitution. Because content/JS/CSS all end up inside one
 * compiled artifact, the executable-surface detectors below scan the
 * combined bytes of all three.
 *
 * Detection regex for scripts / inline handlers / dangerous URLs / Blade
 * includes / raw echoes are reimplemented (not imported) from the
 * read-only Gate 0 inventory command
 * \Cms\Core\Console\Commands\AuditLegacyWidgetsCommand, keeping the exact
 * same patterns per this task's design notes so module-owned eligibility
 * semantics stay consistent with that frozen read-only inventory; the
 * Core command itself stays untouched and gains no module knowledge.
 *
 * @see \Cms\Widget\Rendering\Eligibility\WidgetSiteLayoutEligibility
 * @see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer
 */
final class LegacyWidgetExecutableSurfaceAnalyzer
{
    /**
     * Inline DOM event handler attributes (onclick=, onerror=, ...).
     * Mirrors AuditLegacyWidgetsCommand::INLINE_HANDLER_PATTERN.
     */
    private const INLINE_HANDLER_PATTERN = '/\son[a-z]+\s*=/i';

    /** Opening <script> tags. Mirrors AuditLegacyWidgetsCommand::SCRIPT_TAG_PATTERN. */
    private const SCRIPT_TAG_PATTERN = '/<script\b/i';

    /**
     * Dangerous URL schemes usable as script gadgets. Mirrors
     * AuditLegacyWidgetsCommand::DANGEROUS_URL_PATTERN, including its
     * boundary fix excluding identifiers that merely end with a scheme
     * word (metadata:, so-data:, ...).
     */
    private const DANGEROUS_URL_PATTERN = '/(?<![\w-])(?:javascript|vbscript|data)\s*:/i';

    /**
     * Blade directives that pull in or execute other templates/PHP.
     * Mirrors AuditLegacyWidgetsCommand::BLADE_INCLUDE_PATTERN.
     */
    private const BLADE_INCLUDE_PATTERN =
        '/@(include|includeIf|includeWhen|includeUnless|includeFirst|extends|component|each|inject|php)\b/';

    /** Raw (unescaped) Blade echoes. Mirrors AuditLegacyWidgetsCommand::RAW_ECHO_PATTERN. */
    private const RAW_ECHO_PATTERN = '/\{!!/';

    /**
     * Request/session/auth/service-access surface: NOT covered by
     * AuditLegacyWidgetsCommand (new for this fingerprint per plan line
     * 572). Detects Laravel request/session/auth/cookie/container helper
     * and facade usage plus PHP superglobals -- all reachable from raw
     * `@php`/`{!! !!}` theme content, since content is never sanitized.
     * Over-inclusive by design: a false positive only flags MORE surface,
     * never fewer, which is the safe bias for a security fingerprint.
     */
    private const REQUEST_SESSION_AUTH_SERVICE_ACCESS_PATTERN =
        '/\b(?:request|session|auth|cookie|app|resolve)\s*\(|(?<!\w)(?:Request|Session|Auth|Cookie|App)::|\$_(?:SERVER|SESSION|COOKIE|GET|POST|REQUEST|ENV)\b/i';

    /**
     * {query:type.name ...} provider placeholder. Mirrors
     * AuditLegacyWidgetsCommand::PROVIDER_PLACEHOLDER_PATTERN.
     */
    private const QUERY_PLACEHOLDER_PATTERN = '/\{query:(\w+)\.(\w+)/';

    /** {list:name ...} placeholder (evidence doc §1.3). */
    private const LIST_PLACEHOLDER_PATTERN = '/\{list:(\w+)/';

    /** {image ...} placeholder (evidence doc §1.3). */
    private const IMAGE_PLACEHOLDER_PATTERN = '/\{image\b/';

    /** {upload ...} placeholder (evidence doc §1.3). */
    private const UPLOAD_PLACEHOLDER_PATTERN = '/\{upload\b/';

    /** {iSeo:key} placeholder (evidence doc §1.3). */
    private const ISEO_PLACEHOLDER_PATTERN = '/\{iSeo:(\w+)/';

    /**
     * Plain {inputName} substitution (evidence doc §1.3), excluding the
     * five prefixed grammars above so each placeholder family is counted
     * once.
     */
    private const PLAIN_INPUT_PLACEHOLDER_PATTERN = '/\{(?!query:|list:|image\b|upload\b|iSeo:)(\w+)\}/';

    /**
     * {repeat:NAME template=`...`} region. Its item template is part of the
     * Theme content, so the executable-surface detectors already scan it;
     * only its item placeholders need the grammar below.
     */
    private const REPEAT_PLACEHOLDER_PATTERN = '/\{repeat:([a-z][a-z0-9_]*)\s+template=`([\s\S]*?)`}/';

    /** {NAME.FIELD} / {NAME.#} item placeholder inside a repeat region. */
    private const REPEAT_ITEM_PLACEHOLDER_PATTERN = '/\{[a-z][a-z0-9_]*\.(?:[A-Za-z0-9_]+|#)\}/';

    /**
     * @param array<string, array{type: string}> $canonicalSchema resolved CanonicalWidgetSchemaResolver output
     * @param array<int, string> $applicability project/template applicability names
     */
    public function analyze(
        string $widgetJs,
        string $themeContent,
        string $themeCss,
        array $canonicalSchema,
        string $policyVersion,
        string $trustLane,
        array $applicability,
        string $artifactBytes = '',
    ): WidgetExecutableSurfaceFingerprint {
        $jsHash = hash('sha256', $widgetJs);
        $themeContentHash = hash('sha256', $themeContent);
        $themeCssHash = hash('sha256', $themeCss);

        // Content, Widget JS, and CSS all end up inside the SAME compiled
        // artifact (evidence doc §1.2), so executable-surface detection
        // scans their combined bytes rather than content alone.
        $combined = $themeContent . "\n" . $widgetJs . "\n" . $themeCss;

        $detections = $this->detectExecutableSurfaces($combined);
        $detectionsHash = hash('sha256', $this->canonicalJson($detections));

        $requestSessionAuthServiceAccessCount = (int) preg_match_all(
            self::REQUEST_SESSION_AUTH_SERVICE_ACCESS_PATTERN,
            $combined
        );
        $requestSessionAuthServiceAccessHash = hash('sha256', (string) $requestSessionAuthServiceAccessCount);

        // Provider/template placeholder grammar is only rewritten inside
        // Theme content (evidence doc §1.3) -- scanning widgetJs here would
        // false-positive on ordinary JS object-literal braces.
        $providerSyntax = $this->detectProviderSyntax($themeContent);
        $providerSyntaxHash = hash('sha256', $this->canonicalJson($providerSyntax));

        $canonicalSchemaHash = hash('sha256', $this->canonicalJson($canonicalSchema));
        $artifactBytesHash = hash('sha256', $artifactBytes);

        $normalizedApplicability = $this->sortedUnique($applicability);
        $applicabilityHash = hash('sha256', $this->canonicalJson($normalizedApplicability));

        $manifestHash = hash('sha256', $this->canonicalJson([
            'js_hash' => $jsHash,
            'theme_content_hash' => $themeContentHash,
            'theme_css_hash' => $themeCssHash,
            'detections_hash' => $detectionsHash,
            'request_session_auth_service_access_hash' => $requestSessionAuthServiceAccessHash,
            'provider_syntax_hash' => $providerSyntaxHash,
            'canonical_schema_hash' => $canonicalSchemaHash,
            'artifact_bytes_hash' => $artifactBytesHash,
            'policy_version' => $policyVersion,
            'trust_lane' => $trustLane,
            'applicability_hash' => $applicabilityHash,
        ]));

        return new WidgetExecutableSurfaceFingerprint(
            jsHash: $jsHash,
            themeContentHash: $themeContentHash,
            themeCssHash: $themeCssHash,
            detections: $detections,
            detectionsHash: $detectionsHash,
            requestSessionAuthServiceAccessCount: $requestSessionAuthServiceAccessCount,
            requestSessionAuthServiceAccessHash: $requestSessionAuthServiceAccessHash,
            providerSyntax: $providerSyntax,
            providerSyntaxHash: $providerSyntaxHash,
            canonicalSchemaHash: $canonicalSchemaHash,
            artifactBytesHash: $artifactBytesHash,
            policyVersion: $policyVersion,
            trustLane: $trustLane,
            applicability: $normalizedApplicability,
            applicabilityHash: $applicabilityHash,
            manifestHash: $manifestHash,
        );
    }

    /**
     * @return array{inline_event_handlers: int, script_tags: int, dangerous_urls: int, blade_includes: int, raw_echoes: int}
     */
    private function detectExecutableSurfaces(string $combined): array
    {
        return [
            'inline_event_handlers' => (int) preg_match_all(self::INLINE_HANDLER_PATTERN, $combined),
            'script_tags' => (int) preg_match_all(self::SCRIPT_TAG_PATTERN, $combined),
            'dangerous_urls' => (int) preg_match_all(self::DANGEROUS_URL_PATTERN, $combined),
            'blade_includes' => (int) preg_match_all(self::BLADE_INCLUDE_PATTERN, $combined),
            'raw_echoes' => (int) preg_match_all(self::RAW_ECHO_PATTERN, $combined),
        ];
    }

    /**
     * @return array{query_keys: list<string>, has_list: bool, has_image: bool, has_upload: bool, has_iseo: bool, has_plain_input_placeholder: bool, repeat_names?: list<string>}
     */
    private function detectProviderSyntax(string $themeContent): array
    {
        $syntax = [
            'query_keys' => $this->extractQueryKeys($themeContent),
            'has_list' => (bool) preg_match(self::LIST_PLACEHOLDER_PATTERN, $themeContent),
            'has_image' => (bool) preg_match(self::IMAGE_PLACEHOLDER_PATTERN, $themeContent),
            'has_upload' => (bool) preg_match(self::UPLOAD_PLACEHOLDER_PATTERN, $themeContent),
            'has_iseo' => (bool) preg_match(self::ISEO_PLACEHOLDER_PATTERN, $themeContent),
            'has_plain_input_placeholder' => (bool) preg_match(self::PLAIN_INPUT_PLACEHOLDER_PATTERN, $themeContent),
        ];

        // Added only when a repeat region exists, so fingerprints of themes without one stay unchanged.
        if (preg_match_all(self::REPEAT_PLACEHOLDER_PATTERN, $themeContent, $repeats) > 0) {
            $syntax['repeat_names'] = $this->sortedUnique($repeats[1]);
            foreach ($repeats[2] as $itemTemplate) {
                if (preg_match(self::REPEAT_ITEM_PLACEHOLDER_PATTERN, $itemTemplate) === 1) {
                    $syntax['has_plain_input_placeholder'] = true;
                }
            }
        }

        return $syntax;
    }

    /**
     * @return list<string>
     */
    private function extractQueryKeys(string $content): array
    {
        if (preg_match_all(self::QUERY_PLACEHOLDER_PATTERN, $content, $matches) === 0) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $index => $type) {
            $keys[] = $type . '.' . $matches[2][$index];
        }

        return $this->sortedUnique($keys);
    }

    /**
     * @param array<int, string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique(array_map('strval', $values)));
        sort($unique);

        return $unique;
    }

    /**
     * Canonical, deterministically-ordered JSON, following the same
     * discipline as
     * \Cms\Core\Services\Widget\LegacyWidgetArtifactGenerationManifest::toCanonicalJson()
     * (recursive key sort + JSON_PRETTY_PRINT/JSON_UNESCAPED_SLASHES) so
     * identical logical content always hashes identically regardless of
     * key insertion order.
     *
     * @param array<array-key, mixed> $data
     */
    private function canonicalJson(array $data): string
    {
        return json_encode(
            $this->ksortRecursive($data),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function ksortRecursive(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->ksortRecursive($value);
            }
        }
        unset($value);

        ksort($data);

        return $data;
    }
}
