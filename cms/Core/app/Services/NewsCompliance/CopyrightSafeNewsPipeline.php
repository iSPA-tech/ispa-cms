<?php

namespace Cms\Core\Services\NewsCompliance;

use Cms\Core\Services\Ai\AiHtmlPromptSanitizer;
use Cms\Core\Services\Ai\AiStructuredExtractionResult;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Illuminate\Support\Str;
use Throwable;

final class CopyrightSafeNewsPipeline
{
    public function __construct(
        private readonly AiStructuredExtractionService $extractor,
        private readonly AiHtmlPromptSanitizer $htmlSanitizer,
        private readonly BraveNewsSearchProvider $searchProvider,
        private readonly NewsEvidenceCrawler $evidenceCrawler,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        if (! (bool) config('core.news_compliance.enabled', true)) {
            return $this->failedResult('disabled', ['Core news compliance pipeline is disabled.']);
        }

        $module = trim((string) ($input['module'] ?? 'Core'));
        $seed = NewsSourceDocument::fromArray((array) ($input['seed'] ?? []), true);
        if ($seed->url === '' || $seed->title === '') {
            return $this->failedResult('invalid_seed', ['Pipeline requires seed.url and seed.title.']);
        }
        $articleProfile = $this->selectArticleProfile($seed, $input);
        $input = [
            ...$input,
            'article_profile' => $articleProfile,
        ];

        $errors = [];
        $documents = $this->seedAndProvidedDocuments($seed, (array) ($input['related_sources'] ?? []), (bool) data_get($input, 'crawl_provided_sources', false), $errors);
        $searchResults = [];

        if ($this->discoveryEnabled($input)) {
            $discovered = $this->discoverRelatedSources($seed, $input, $errors);
            $searchResults = $discovered['search_results'];
            $documents = $this->mergeDocuments($documents, $discovered['documents']);
        }

        $factResult = $this->extractFactSheet($module, $seed, $documents, $input);
        if (! $factResult->ok || ! is_array($factResult->data)) {
            return $this->failedResult('fact_extraction_failed', $factResult->errors, [
                'seed' => $seed->toArray(),
                'article_profile' => $articleProfile,
                'search_results' => array_map(static fn (NewsSearchResult $result): array => $result->toArray(), $searchResults),
                'document_count' => count($documents),
                'discovery_errors' => $errors,
                'ai' => ['fact_extraction' => $this->aiRunLog($factResult, 'core_news_compliance_fact_sheet')],
            ]);
        }

        $verifiedFactSheet = $this->verifiedFactSheet($factResult->data, $input);
        $verifiedFactSheet['article_profile'] = $articleProfile;

        $draftResult = $this->draftFromFactSheet($module, $verifiedFactSheet, $input);
        if (! $draftResult->ok || ! is_array($draftResult->data)) {
            return $this->failedResult('draft_failed', $draftResult->errors, [
                'seed' => $seed->toArray(),
                'article_profile' => $articleProfile,
                'verified_fact_sheet' => $verifiedFactSheet,
                'search_results' => array_map(static fn (NewsSearchResult $result): array => $result->toArray(), $searchResults),
                'document_count' => count($documents),
                'discovery_errors' => $errors,
                'ai' => [
                    'fact_extraction' => $this->aiRunLog($factResult, 'core_news_compliance_fact_sheet'),
                    'draft' => $this->aiRunLog($draftResult, 'core_news_compliance_draft'),
                ],
            ]);
        }

        $initialDraftResult = null;
        $independenceDraftResult = null;
        $minimumDraftWords = $this->minimumDraftWords($input);
        $draftWordCount = $this->draftWordCount($draftResult->data);
        if ($minimumDraftWords > 0 && $draftWordCount < $minimumDraftWords) {
            $initialDraftResult = $draftResult;
            $revisedDraftResult = $this->draftFromFactSheet($module, $verifiedFactSheet, [
                ...$input,
                'draft_revision_note' => sprintf(
                    'Previous draft was too short (%d words). Expand it to at least %d words while still using only verified facts and avoiding source phrasing.',
                    $draftWordCount,
                    $minimumDraftWords,
                ),
            ]);

            if ($revisedDraftResult->ok && is_array($revisedDraftResult->data)) {
                $draftResult = $revisedDraftResult;
            }
        }

        $preReviewMechanical = $this->mechanicalReview($verifiedFactSheet, $draftResult->data, $documents, $input);
        if (! (bool) ($preReviewMechanical['passed'] ?? false) && $this->rewriteOnIndependenceFailure($input)) {
            $initialDraftResult ??= $draftResult;
            $independenceDraftResult = $this->draftFromFactSheet($module, $verifiedFactSheet, [
                ...$input,
                'draft_revision_note' => $this->mechanicalRevisionNote($preReviewMechanical),
            ]);

            if ($independenceDraftResult->ok && is_array($independenceDraftResult->data)) {
                $draftResult = $independenceDraftResult;
            }
        }

        $reviewResult = $this->reviewDraft($module, $verifiedFactSheet, $draftResult->data, $documents, $input);
        $review = is_array($reviewResult->data) ? $reviewResult->data : [
            'passed' => false,
            'risk_level' => 'high',
            'issues' => array_map(static fn (string $error): array => [
                'type' => 'fact',
                'detail' => $error,
                'severity' => 'high',
            ], $reviewResult->errors),
            'required_actions' => ['Manual editorial/legal review required.'],
            'image_rights_passed' => false,
            'confidence' => 0.0,
        ];
        $review['model_review_passed'] = (bool) ($review['passed'] ?? false);

        $mechanicalReview = $this->mechanicalReview($verifiedFactSheet, $draftResult->data, $documents, $input);
        $review = $this->applyMechanicalReview($review, $mechanicalReview);

        $relatedCount = max(0, count($documents) - 1);
        $minimumRelated = $this->minimumRelatedSourcesForAutoPass($input);
        if ($relatedCount < $minimumRelated) {
            $review['passed'] = false;
            $review['risk_level'] = $this->highestRiskLevel((string) ($review['risk_level'] ?? 'low'), 'medium');
            $review['issues'][] = [
                'type' => 'fact',
                'detail' => "Only {$relatedCount} related source(s) were available; auto-publish requires at least {$minimumRelated}.",
                'severity' => 'medium',
            ];
            $review['required_actions'][] = 'Add independent source evidence or approve manually.';
        }

        $review = $this->applyFinalGate($review, $mechanicalReview);
        $passed = (bool) ($review['passed'] ?? false);

        return [
            'status' => $passed ? 'approved' : 'needs_manual_review',
            'claim_status' => $this->pipelineClaimStatus($passed),
            'seed' => $seed->toArray(),
            'article_profile' => $articleProfile,
            'related_sources' => array_map(static fn (NewsSourceDocument $document): array => $document->toReference('fact'), array_slice($documents, 1)),
            'search_results' => array_map(static fn (NewsSearchResult $result): array => $result->toArray(), $searchResults),
            'verified_fact_sheet' => $verifiedFactSheet,
            'draft' => $draftResult->data,
            'review' => $review,
            'mechanical_review' => $mechanicalReview,
            'published_article' => $passed ? $this->publishedArticlePayload($draftResult->data, $documents, $review) : null,
            'compliance_log' => [
                'fact_only_drafting' => true,
                'drafting_raw_source_access' => false,
                'legal_review_passed' => $passed,
                'image_rights_passed' => (bool) ($review['image_rights_passed'] ?? false),
                'mechanical_review_passed' => (bool) ($mechanicalReview['passed'] ?? false),
                'mechanical_review_risk_level' => (string) ($mechanicalReview['risk_level'] ?? 'low'),
                'source_link_required' => true,
                'source_count' => count($documents),
                'related_source_count' => $relatedCount,
                'decree_context' => 'Nghị định 134/2026/NĐ-CP copyright/related-rights compliance guard.',
            ],
            'discovery_errors' => $errors,
            'ai' => array_filter([
                'fact_extraction' => $this->aiRunLog($factResult, 'core_news_compliance_fact_sheet'),
                'draft_initial' => $initialDraftResult === null ? null : $this->aiRunLog($initialDraftResult, 'core_news_compliance_draft'),
                'draft_independence_revision' => $independenceDraftResult === null ? null : $this->aiRunLog($independenceDraftResult, 'core_news_compliance_draft'),
                'draft' => $this->aiRunLog($draftResult, 'core_news_compliance_draft'),
                'review' => $this->aiRunLog($reviewResult, 'core_news_compliance_review'),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string> $errors
     * @return array{search_results: list<NewsSearchResult>, documents: list<NewsSourceDocument>}
     */
    public function discoverRelatedSources(NewsSourceDocument $seed, array $input = [], array &$errors = []): array
    {
        $query = trim((string) ($input['target_topic'] ?? $input['search_query'] ?? $seed->title));
        if ($query === '') {
            return ['search_results' => [], 'documents' => []];
        }

        $limit = max(1, min(5, (int) ($input['related_limit'] ?? config('core.news_compliance.discovery.max_related_sources', 5))));

        try {
            $results = $this->searchProvider->search($query, $limit + 3);
        } catch (Throwable $exception) {
            $errors[] = 'Search failed: ' . $exception->getMessage();

            return ['search_results' => [], 'documents' => []];
        }

        $excludedHosts = $this->excludedHosts($seed, $input);
        $acceptedResults = [];
        $documents = [];

        foreach ($results as $result) {
            if (count($documents) >= $limit) {
                break;
            }

            if (! $this->searchResultAllowed($result, $seed, $excludedHosts)) {
                continue;
            }

            $acceptedResults[] = $result;

            try {
                $documents[] = $this->evidenceCrawler->crawl($result);
            } catch (Throwable $exception) {
                $errors[] = sprintf('Evidence crawl failed for [%s]: %s', $result->url, $exception->getMessage());
            }

            $this->searchDelay();
        }

        return ['search_results' => $acceptedResults, 'documents' => $documents];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function selectArticleProfile(NewsSourceDocument $seed, array $input): array
    {
        if (isset($input['article_profile']) && is_array($input['article_profile'])) {
            $profile = (string) data_get($input['article_profile'], 'selected_profile', data_get($input['article_profile'], 'profile', 'general_news'));
            $policy = $this->pipelineProfilePolicy($profile);

            return [
                ...$policy,
                ...$input['article_profile'],
                'selected_profile' => (string) data_get($input['article_profile'], 'selected_profile', $policy['profile']),
                'why_this_profile' => 'Profile provided by pipeline input.',
            ];
        }

        $forced = trim((string) ($input['article_profile'] ?? $input['pipeline_profile'] ?? ''));
        $profile = $forced !== '' ? $forced : $this->classifyArticleType($seed, $input);
        $policy = $this->pipelineProfilePolicy($profile);

        return [
            'selected_profile' => $policy['profile'],
            'primary_article_type' => $policy['primary_article_type'],
            'secondary_risk_types' => $policy['secondary_risk_types'],
            'factual_volatility' => $policy['factual_volatility'],
            'legal_risk' => $policy['legal_risk'],
            'attribution_strictness' => $policy['attribution_strictness'],
            'source_freshness_required' => $policy['source_freshness_required'],
            'quote_risk' => $policy['quote_risk'],
            'image_rights_risk' => $policy['image_rights_risk'],
            'similarity_threshold_level' => $policy['similarity_threshold_level'],
            'allowed_inference' => $policy['allowed_inference'],
            'required_review_gates' => $policy['required_review_gates'],
            'source_requirements' => $policy['source_requirements'],
            'fact_schema_requirements' => $policy['fact_schema_requirements'],
            'drafting_constraints' => $policy['drafting_constraints'],
            'manual_review_triggers' => $policy['manual_review_triggers'],
            'why_this_profile' => $forced !== ''
                ? 'Profile forced by pipeline input.'
                : 'Profile selected by deterministic seed-title/content risk keywords.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function classifyArticleType(NewsSourceDocument $seed, array $input): string
    {
        $titleText = $this->normalizedTextForReview(implode(' ', [
            $seed->title,
            $seed->summary,
            (string) ($input['target_topic'] ?? ''),
        ]));
        $bodyText = $this->normalizedTextForReview(Str::limit($seed->content, 1200, ''));

        $profileNeedles = [
            'health_medical' => ['benh', 'y te', 'thuoc', 'bac si', 'dieu tri', 'vaccine', 'virus', 'ung thu', 'dich'],
            'dispute_news' => ['cao buoc', 'bac bo', 'phu nhan', 'tranh cai', 'khieu khich', 'xung dot', 'chien dau', 'tau san bay', 'ngoai giao'],
            'weather_public_safety' => ['mua', 'giong', 'gio', 'bao', 'ngap', 'lu', 'sat lo', 'thoi tiet', 'mm', 'cao diem'],
            'legal_crime_news' => ['bat giu', 'khoi to', 'toa an', 'xet xu', 'an tu', 'phap luat', 'nghi dinh', 'bo luat', 'toi danh'],
            'tech_product' => ['iphone', 'android', 'laptop', 'chip', 'tri tue nhan tao', 'cong nghe', 'san pham', 'ra mat', 'cau hinh', 'gia ban'],
            'finance_market' => ['thi truong', 'co phieu', 'chung khoan', 'lai suat', 'gia vang', 'usd', 'ty gia', 'ngan hang', 'bitcoin'],
            'sports' => ['tran dau', 'bong da', 'hlv', 'doi tuyen', 'giai dau', 'ty so', 'lich thi dau', 'bang xep hang'],
            'entertainment' => ['ca si', 'dien vien', 'showbiz', 'hoa hau', 'phim', 'concert', 'mv', 'nghe si'],
            'evergreen_guide' => ['huong dan', 'cach', 'thu tuc', 'can biet', 'nen lam', 'checklist', 'quy trinh'],
            'opinion_commentary' => ['binh luan', 'goc nhin', 'quan diem', 'y kien'],
            'breaking_news' => ['khan cap', 'vua xay ra', 'moi nhat', 'dang cap nhat'],
        ];
        $profilePriority = array_flip(array_keys($profileNeedles));
        $scores = [];

        foreach ($profileNeedles as $profile => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($titleText, $needle)) {
                    $scores[$profile] = ($scores[$profile] ?? 0) + 3;
                } elseif (str_contains($bodyText, $needle)) {
                    $scores[$profile] = ($scores[$profile] ?? 0) + 1;
                }
            }
        }

        if ($scores !== []) {
            arsort($scores);
            $bestScore = max($scores);
            $candidates = array_keys(array_filter($scores, static fn (int $score): bool => $score === $bestScore));
            usort(
                $candidates,
                static fn (string $left, string $right): int => ($profilePriority[$left] ?? 999) <=> ($profilePriority[$right] ?? 999),
            );

            return $candidates[0];
        }

        return (string) ($input['publish_mode'] ?? '') === 'explainer' ? 'explainer' : 'general_news';
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineProfilePolicy(string $profile): array
    {
        $base = [
            'profile' => 'general_news',
            'primary_article_type' => 'general_news',
            'secondary_risk_types' => [],
            'factual_volatility' => 'medium',
            'legal_risk' => 'medium',
            'attribution_strictness' => 'medium',
            'source_freshness_required' => 'medium',
            'quote_risk' => 'medium',
            'image_rights_risk' => 'high',
            'similarity_threshold_level' => 'strict',
            'allowed_inference' => 'low',
            'required_review_gates' => ['fact_support', 'wording_similarity', 'fact_sheet_similarity', 'image_rights', 'final_gate'],
            'source_requirements' => ['Prefer independent sources; identify reused wire/source chains when visible.'],
            'fact_schema_requirements' => ['Use atomic facts; separate claims, denials, opinion, numbers, and dates.'],
            'drafting_constraints' => ['Draft only from structured fact records; every public sentence needs fact or inference mapping.'],
            'manual_review_triggers' => ['High similarity, unsupported public sentence, blocked fact leakage, unlicensed image.'],
        ];

        $profiles = [
            'dispute_news' => [
                'primary_article_type' => 'dispute_news',
                'secondary_risk_types' => ['legal', 'attribution'],
                'factual_volatility' => 'high',
                'legal_risk' => 'high',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'high',
                'similarity_threshold_level' => 'strict',
                'allowed_inference' => 'low',
                'required_review_gates' => ['claim_status', 'same_sentence_attribution', 'source_independence', 'wording_similarity', 'fact_sheet_similarity', 'legal_review', 'image_rights', 'final_gate'],
                'source_requirements' => ['Separate claim actor from publication source.', 'Do not count copied agency chains as independent evidence without review.'],
                'fact_schema_requirements' => ['Allegations stay official_claim.', 'Denials stay denial.', 'Unverified details remain blocked.'],
                'drafting_constraints' => ['Do not use proof/truth framing unless claim truth is independently verified.', 'No blocked details in public body.'],
                'manual_review_triggers' => ['Any unattributed contested claim.', 'Any allegation converted into verified fact.', 'Any blocked detail in public body.'],
            ],
            'legal_crime_news' => [
                'primary_article_type' => 'legal_crime_news',
                'secondary_risk_types' => ['legal', 'identity'],
                'factual_volatility' => 'high',
                'legal_risk' => 'high',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'strict',
                'allowed_inference' => 'low',
                'required_review_gates' => ['presumption_wording', 'identity_policy', 'legal_review', 'fact_support', 'final_gate'],
                'source_requirements' => ['Prefer official court, police, regulator, or legal document sources.'],
                'fact_schema_requirements' => ['Track legal status, decision stage, date, agency, and whether a judgment is final.'],
                'drafting_constraints' => ['Avoid conviction language unless there is a final judgment.', 'Do not infer motive or guilt.'],
                'manual_review_triggers' => ['Named individuals, minors, pending charges, allegations without official source.'],
            ],
            'health_medical' => [
                'primary_article_type' => 'health_medical',
                'secondary_risk_types' => ['medical_safety'],
                'factual_volatility' => 'high',
                'legal_risk' => 'high',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'strict',
                'allowed_inference' => 'low',
                'required_review_gates' => ['medical_source_quality', 'no_personal_medical_advice', 'limitations', 'final_gate'],
                'source_requirements' => ['Prefer official health agencies, guidelines, journals, or credentialed experts.'],
                'fact_schema_requirements' => ['Track population, sample size, limitation, guidance status, and date.'],
                'drafting_constraints' => ['No treatment advice for individuals.', 'No broad certainty from limited evidence.'],
                'manual_review_triggers' => ['Treatment claims, dosage, diagnosis, outbreak guidance, vulnerable populations.'],
            ],
            'finance_market' => [
                'primary_article_type' => 'finance_market',
                'secondary_risk_types' => ['financial'],
                'factual_volatility' => 'high',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'low',
                'required_review_gates' => ['timestamped_numbers', 'no_investment_advice', 'source_freshness', 'final_gate'],
                'source_requirements' => ['Timestamp market data and prefer exchange, company, regulator, or official data sources.'],
                'fact_schema_requirements' => ['Track metric, value, unit, timestamp, market, and source.'],
                'drafting_constraints' => ['Do not write personal investment advice.', 'Separate analyst opinion from market fact.'],
                'manual_review_triggers' => ['Forward-looking claims, investment recommendations, stale prices.'],
            ],
            'tech_product' => [
                'primary_article_type' => 'tech_product',
                'secondary_risk_types' => ['product_accuracy'],
                'factual_volatility' => 'medium',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'high',
                'quote_risk' => 'low',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'medium',
                'required_review_gates' => ['official_specs', 'current_price', 'rumor_separation', 'image_rights', 'final_gate'],
                'source_requirements' => ['Prefer official specs, launch pages, company statements, benchmark source, or retailer page for price.'],
                'fact_schema_requirements' => ['Track specs, price, availability date, region, benchmark source, and rumor status.'],
                'drafting_constraints' => ['Separate official specs from review claims or rumors.', 'Standard product section structure is allowed when wording is original.'],
                'manual_review_triggers' => ['Rumors, affiliate bias, benchmark claims without source, stale price.'],
            ],
            'sports' => [
                'primary_article_type' => 'sports',
                'secondary_risk_types' => ['recency'],
                'factual_volatility' => 'high',
                'legal_risk' => 'low',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'high',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'medium',
                'required_review_gates' => ['current_score_schedule_standings', 'quote_attribution', 'final_gate'],
                'source_requirements' => ['Prefer official league, club, match center, or reliable live-data sources.'],
                'fact_schema_requirements' => ['Track score, schedule, standings, date/time, competition, and quote speaker.'],
                'drafting_constraints' => ['Avoid stale live data.', 'Keep quotes attributed.'],
                'manual_review_triggers' => ['Live score changed, disciplinary claims, injury speculation.'],
            ],
            'weather_public_safety' => [
                'primary_article_type' => 'weather_public_safety',
                'secondary_risk_types' => ['recency', 'public_safety'],
                'factual_volatility' => 'high',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'low',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'low',
                'required_review_gates' => ['freshness_timestamp', 'location_specific_numbers', 'official_forecast_source', 'public_safety_uncertainty', 'final_gate'],
                'source_requirements' => ['Prefer official meteorological agency, hydrology, disaster-response, and current local traffic/public-safety sources.'],
                'fact_schema_requirements' => ['Track location, rainfall/wind value, unit, observation time, forecast window, warning level, and source timestamp.'],
                'drafting_constraints' => ['Do not overstate forecast certainty.', 'Keep warning and travel guidance tied to official or time-stamped sources.'],
                'manual_review_triggers' => ['Rapidly changing forecast, flood/landslide warning, casualty/damage claims, missing timestamp.'],
            ],
            'entertainment' => [
                'primary_article_type' => 'entertainment',
                'secondary_risk_types' => ['privacy', 'image_rights'],
                'factual_volatility' => 'medium',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'medium',
                'quote_risk' => 'high',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'low',
                'required_review_gates' => ['rumor_labeling', 'privacy', 'quote_rights', 'image_rights', 'final_gate'],
                'source_requirements' => ['Prefer original statements, official accounts, organizers, labels, studios, or direct interviews.'],
                'fact_schema_requirements' => ['Track original quote source, event, date, role, and rumor status.'],
                'drafting_constraints' => ['Do not amplify unverified private-life rumors.', 'Avoid source photo reuse without rights.'],
                'manual_review_triggers' => ['Private individuals, minors, dating/health rumors, unattributed quotes.'],
            ],
            'evergreen_guide' => [
                'primary_article_type' => 'evergreen_guide',
                'secondary_risk_types' => ['freshness'],
                'factual_volatility' => 'medium',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'medium',
                'quote_risk' => 'low',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'high',
                'required_review_gates' => ['freshness_policy', 'step_validation', 'regulated_domain_review_if_needed', 'final_gate'],
                'source_requirements' => ['Prefer current official instructions, policy pages, app docs, or law/regulation pages.'],
                'fact_schema_requirements' => ['Track steps, prerequisites, warnings, version/date, and applicability.'],
                'drafting_constraints' => ['Checklist or process angle is allowed when each step maps to source facts.'],
                'manual_review_triggers' => ['Legal, medical, financial, or software-version-sensitive guidance.'],
            ],
            'explainer' => [
                'primary_article_type' => 'explainer',
                'secondary_risk_types' => ['inference'],
                'factual_volatility' => 'medium',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'medium',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'medium',
                'allowed_inference' => 'medium',
                'required_review_gates' => ['inference_map', 'source_breadth', 'final_gate'],
                'source_requirements' => ['Use enough independent background to support explanation, not only seed article.'],
                'fact_schema_requirements' => ['Track definitions, timeline, causes, impact, uncertainty, and inference links.'],
                'drafting_constraints' => ['Each inference must map to supporting facts.', 'Do not overstate causal certainty.'],
                'manual_review_triggers' => ['Weak causal inference, disputed background, regulated topics.'],
            ],
            'breaking_news' => [
                'primary_article_type' => 'breaking_news',
                'secondary_risk_types' => ['recency', 'uncertainty'],
                'factual_volatility' => 'high',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'high',
                'source_freshness_required' => 'high',
                'quote_risk' => 'medium',
                'similarity_threshold_level' => 'strict',
                'allowed_inference' => 'low',
                'required_review_gates' => ['freshness_timestamp', 'uncertainty_label', 'source_update_check', 'final_gate'],
                'source_requirements' => ['Prefer official updates and most recent source; fewer sources may be acceptable only with manual review.'],
                'fact_schema_requirements' => ['Track latest confirmed update, timestamp, source, and uncertainty.'],
                'drafting_constraints' => ['Use developing language when facts are not stable.', 'Do not imply finality.'],
                'manual_review_triggers' => ['Casualties, identity, cause, active incident, sparse sourcing.'],
            ],
            'opinion_commentary' => [
                'primary_article_type' => 'opinion_commentary',
                'secondary_risk_types' => ['opinion_labeling'],
                'factual_volatility' => 'low',
                'legal_risk' => 'medium',
                'attribution_strictness' => 'medium',
                'source_freshness_required' => 'medium',
                'quote_risk' => 'high',
                'similarity_threshold_level' => 'strict',
                'allowed_inference' => 'medium',
                'required_review_gates' => ['opinion_label', 'fact_opinion_separation', 'quote_discipline', 'final_gate'],
                'source_requirements' => ['Do not convert another author opinion into your own objective news fact.'],
                'fact_schema_requirements' => ['Separate author opinions, evidence, counterpoints, and extractable facts.'],
                'drafting_constraints' => ['Clearly label commentary; factual claims still require support.'],
                'manual_review_triggers' => ['Unattributed opinion, defamatory claim, copied argument structure.'],
            ],
        ];

        return [
            ...$base,
            ...($profiles[$profile] ?? []),
            'profile' => array_key_exists($profile, $profiles) ? $profile : 'general_news',
        ];
    }

    /**
     * @param array<int, mixed> $provided
     * @param array<int, string> $errors
     * @return list<NewsSourceDocument>
     */
    private function seedAndProvidedDocuments(NewsSourceDocument $seed, array $provided, bool $crawlProvidedSources, array &$errors): array
    {
        $documents = [$seed];

        foreach ($provided as $source) {
            $document = $source instanceof NewsSourceDocument ? $source : NewsSourceDocument::fromArray((array) $source);
            if ($document->url === '') {
                continue;
            }

            if ($document->content === '' && $crawlProvidedSources) {
                try {
                    $document = $this->evidenceCrawler->crawl(new NewsSearchResult(
                        provider: 'provided',
                        title: $document->title,
                        url: $document->url,
                        snippet: $document->summary,
                    ));
                } catch (Throwable $exception) {
                    $errors[] = sprintf('Provided source crawl failed for [%s]: %s', $document->url, $exception->getMessage());
                }
            }

            if ($document->hasText()) {
                $documents[] = $document;
            }
        }

        return $this->uniqueDocuments($documents);
    }

    /**
     * @param list<NewsSourceDocument> $left
     * @param list<NewsSourceDocument> $right
     * @return list<NewsSourceDocument>
     */
    private function mergeDocuments(array $left, array $right): array
    {
        return $this->uniqueDocuments([...$left, ...$right]);
    }

    /**
     * @param list<NewsSourceDocument> $documents
     * @return list<NewsSourceDocument>
     */
    private function uniqueDocuments(array $documents): array
    {
        $seen = [];
        $unique = [];

        foreach ($documents as $document) {
            $url = $this->normalizedUrl($document->url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $unique[] = $document;
        }

        return $unique;
    }

    /**
     * @param list<NewsSourceDocument> $documents
     * @param array<string, mixed> $input
     */
    private function extractFactSheet(string $module, NewsSourceDocument $seed, array $documents, array $input): AiStructuredExtractionResult
    {
        return $this->extractor->extract(
            input: $this->factExtractionInput($documents),
            schema: $this->factSheetSchema(),
            instructions: $this->factExtractionInstructions(),
            context: [
                'module' => $module,
                'task' => 'copyright_safe_news_fact_extraction',
                'seed_url' => $seed->url,
                'seed_title' => $seed->title,
                'target_language' => (string) ($input['target_language'] ?? 'vi'),
                'publish_mode' => (string) ($input['publish_mode'] ?? 'original-analysis'),
                'policy' => 'Only fact extraction may read source text. Drafting must use verified fact sheet only.',
                'article_profile' => (array) ($input['article_profile'] ?? []),
            ],
            options: $this->aiOptions($module, 'core_news_compliance_fact_sheet', 'v1'),
        );
    }

    /**
     * @param array<string, mixed> $factSheet
     * @param array<string, mixed> $input
     */
    private function draftFromFactSheet(string $module, array $factSheet, array $input): AiStructuredExtractionResult
    {
        return $this->extractor->extract(
            input: $this->stableJson([
                'verified_fact_sheet' => $this->draftSafeFactSheet($factSheet),
                'drafting_packet' => (array) ($factSheet['drafting_packet'] ?? []),
                'editorial_angle' => (string) ($input['editorial_angle'] ?? ''),
                'target_audience' => (string) ($input['target_audience'] ?? 'Vietnamese readers'),
                'format' => (string) ($input['content_format'] ?? $input['publish_mode'] ?? 'news-brief'),
                'style_guide' => (string) ($input['style_guide'] ?? ''),
                'minimum_word_count' => $this->minimumDraftWords($input),
                'draft_revision_note' => (string) ($input['draft_revision_note'] ?? ''),
            ]),
            schema: $this->draftSchema(),
            instructions: $this->draftInstructions(),
            context: [
                'module' => $module,
                'task' => 'copyright_safe_news_drafting',
                'raw_source_access' => false,
                'drafting_policy' => 'Write an independent article only from sanitized approved facts; do not paraphrase source articles or source-like fact wording.',
            ],
            options: $this->aiOptions($module, 'core_news_compliance_draft', 'v1'),
        );
    }

    /**
     * @param array<string, mixed> $factSheet
     * @param array<string, mixed> $draft
     * @param list<NewsSourceDocument> $documents
     * @param array<string, mixed> $input
     */
    private function reviewDraft(string $module, array $factSheet, array $draft, array $documents, array $input): AiStructuredExtractionResult
    {
        return $this->extractor->extract(
            input: $this->stableJson([
                'draft' => $draft,
                'verified_fact_sheet' => $factSheet,
                'references' => array_map(static fn (NewsSourceDocument $document): array => $document->toReference(), $documents),
                'manual_review_policy' => (string) ($input['manual_review_policy'] ?? 'Require manual review for legal, financial, health, politics, and high-risk public safety topics.'),
            ]),
            schema: $this->reviewSchema(),
            instructions: $this->reviewInstructions(),
            context: [
                'module' => $module,
                'task' => 'copyright_safe_news_legal_editorial_review',
                'review_policy' => 'Reject if draft appears to reconstruct source wording, source structure, unlicensed image use, or unsupported claims.',
            ],
            options: $this->aiOptions($module, 'core_news_compliance_review', 'v1'),
        );
    }

    /**
     * @param list<NewsSourceDocument> $documents
     */
    private function factExtractionInput(array $documents): string
    {
        $maxChars = max(1000, (int) config('core.news_compliance.ai.max_fact_input_chars', 40000));
        $chunks = [];

        foreach ($documents as $index => $document) {
            $body = $this->htmlSanitizer->compactPrompt(
                input: $document->content !== '' ? $document->content : $document->summary,
                baseUrl: $document->url,
                maxTextChars: max(1000, (int) floor($maxChars / max(1, count($documents)))),
                maxLinks: 10,
            );

            $chunks[] = trim(implode("\n", array_filter([
                'SOURCE #' . ($index + 1),
                'seed=' . ($document->seed ? 'true' : 'false'),
                'url=' . $document->url,
                'title=' . $document->title,
                'publisher=' . $document->publisher,
                'author=' . $document->author,
                'published_at=' . ($document->publishedAt ?? ''),
                'source_type=' . $document->sourceType,
                'summary=' . $document->summary,
                'content=' . $body,
            ], static fn (string $line): bool => trim($line) !== '')));
        }

        return Str::limit(implode("\n\n---\n\n", $chunks), $maxChars, '');
    }

    private function factExtractionInstructions(): string
    {
        return implode("\n", [
            'You are the Fact Extraction, Classification, Normalization, and Verification Agent.',
            'Read source text only to extract atomic facts.',
            'Do not draft article prose.',
            'Write facts as neutral data points, not as newspaper prose.',
            'Do not preserve source headline logic, sentence rhythm, or rhetorical framing in fact text.',
            'Separate facts from opinions. Mark opinion language with is_opinion=true.',
            'Classify claims as event, claim, quote, timeline, policy, number, definition. Do not convert allegations or denials into verified facts.',
            'Each fact must contain one idea, source URL/title, fact_type, confidence, and manual-review flag.',
            'Quote spans must be very short; use empty string when no exact quote is necessary.',
            'Normalize duplicated facts into normalized_facts with supporting_sources and contradicting_sources.',
            'Use high confidence only when supported by official source or at least two independent sources.',
            'Put low-confidence, opinion, conflict, legal/health/financial/political/safety uncertainty into manual_review_items.',
            'Use the provided article_profile to choose emphasis: dispute/legal/health need stricter claim status; product/finance/sports need timestamped numbers/specs/current data; guide/explainer need inference support.',
        ]);
    }

    private function draftInstructions(): string
    {
        return implode("\n", [
            'You are the Drafting Agent.',
            'Write a new independent article from the sanitized drafting_packet.',
            'Write only from structured_fact_records and approved_facts in drafting_packet. Do not require prose facts_high or facts_medium in the draft input.',
            'Do not paraphrase source articles.',
            'Do not paraphrase source-like fact wording. Re-express neutral facts in fresh prose.',
            'Do not reconstruct original phrasing or source-specific structure.',
            'Separate fact from commentary.',
            'Use only facts_high and editor-approved facts_medium. Do not use facts_low, blocked_facts, manual_review_items, or unsupported facts as assertions.',
            'If a needed fact is missing from approved facts, write [EDITOR CHECK REQUIRED: missing approved fact about ...] instead of inventing or inferring.',
            'Treat allegations as allegations, denials as denials, unverified details as unverified, and separate background events as optional context only when approved.',
            'Every contested claim or denial must carry concrete attribution in the same sentence.',
            'Use structured_fact_records as data cards. Do not convert any card field into a copied fact-sheet sentence.',
            'Blocked facts are withheld from public drafting. Never mention excluded details in the article, even to say they were excluded.',
            'Write a complete publishable article, not an outline, not a short abstract, and not a bullet summary.',
            'Use the independent angle plan. Reject the simple frame "A accused B; B denied it" unless the angle plan explicitly allows it.',
            'Create a new headline, intro, and section order from the fact sheet. Do not reuse source headlines, source lead logic, or source section order.',
            'Headline must not use the seed claim phrase, the same actor-object cluster as the source headline, or a rhetorical "X hay Y?" restatement of the source dispute.',
            'Do not use phrases such as "the truth verified", "proved", "evidence shows", or "sự thật được xác minh" unless the fact packet confirms independent verification of the claim truth.',
            'Each section body should contain developed article prose with context and transitions, not one copied fact sentence.',
            'No draft sentence should reuse the same token order as an approved fact. Combine, split, or reframe facts so sentence structure is new.',
            'Rewrite atomic facts into fresh wording while preserving numbers, dates, names, and attribution.',
            'For news-brief, target 350-550 Vietnamese words. For original-analysis or explainer, target 650-950 Vietnamese words.',
            'If minimum_word_count is greater than zero, the complete draft must meet or exceed that word count.',
            'If draft_revision_note is present, follow it exactly while preserving all safety rules.',
            'Use facts_used as approved fact IDs from drafting_packet. Do not paste source paragraphs into facts_used.',
            'For every public intro/body sentence, output sentence_fact_map with the exact sentence text and approved_fact_ids or approved_inference_id.',
            'A public fact, attributed_claim, denial, context, or inference sentence without approved_fact_ids or approved_inference_id is not publishable.',
            'Editor commentary is CMS-only. Do not put editor notes, blocked facts, or excluded details in headline, intro, or section bodies.',
            'After drafting, include editor_commentary_blocks with the chosen angle and how the structure differs from the seed/source frame.',
            'Do not include source images unless image_policy explicitly marks them licensed/owned/public-domain.',
            'Use Vietnamese unless target_language says otherwise.',
        ]);
    }

    private function reviewInstructions(): string
    {
        return implode("\n", [
            'You are the Legal and Editorial Review Agent.',
            'Check similarity risk, quote discipline, attribution, normal exploitation risk, image rights, and fact support.',
            'Reject if the draft appears based on wording or structure of one source.',
            'Do not reject merely because a contested news story naturally includes claim-and-denial roles; reject only when wording, headline framing, lead logic, or section order tracks a source.',
            'Reject unsupported claims, long quotes, unlicensed source images, and source opinions presented as facts.',
            'For disputed claims, check whether attribution appears in the same sentence. Claim existence may be verified even when claim truth is unverified.',
            'Apply article_profile risk emphasis: claim-status for disputes, source quality for health/legal, timestamps for finance/sports, official specs for tech, freshness for guides.',
            'Passing requires low risk, source attribution completeness, fact-only drafting, and image rights compliance.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function factSheetSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['topic', 'sources', 'entities', 'facts', 'normalized_facts', 'facts_high', 'facts_medium', 'facts_low', 'manual_review_items', 'confidence'],
            'properties' => [
                'topic' => ['type' => 'string'],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url', 'title', 'publisher', 'published_at', 'source_type', 'independence_score'],
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'publisher' => ['type' => 'string'],
                            'published_at' => ['type' => 'string'],
                            'source_type' => ['type' => 'string'],
                            'independence_score' => ['type' => 'number'],
                        ],
                    ],
                ],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'type'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['person', 'org', 'location', 'product', 'law', 'event']],
                        ],
                    ],
                ],
                'facts' => ['type' => 'array', 'items' => $this->factSchema()],
                'normalized_facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['canonical_fact', 'supporting_sources', 'contradicting_sources', 'confidence', 'manual_review'],
                        'properties' => [
                            'canonical_fact' => ['type' => 'string'],
                            'supporting_sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'contradicting_sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'confidence' => ['type' => 'number'],
                            'manual_review' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'facts_high' => ['type' => 'array', 'items' => ['type' => 'string']],
                'facts_medium' => ['type' => 'array', 'items' => ['type' => 'string']],
                'facts_low' => ['type' => 'array', 'items' => ['type' => 'string']],
                'manual_review_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'number'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function factSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'text', 'fact_type', 'source_url', 'source_title', 'published_at', 'quote_span', 'confidence', 'is_opinion', 'needs_manual_review'],
            'properties' => [
                'id' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'fact_type' => ['type' => 'string', 'enum' => ['event', 'number', 'quote', 'definition', 'timeline', 'policy', 'claim']],
                'source_url' => ['type' => 'string'],
                'source_title' => ['type' => 'string'],
                'published_at' => ['type' => 'string'],
                'quote_span' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'is_opinion' => ['type' => 'boolean'],
                'needs_manual_review' => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'intro', 'sections', 'sentence_fact_map', 'source_mentions', 'editor_commentary_blocks', 'image_policy', 'confidence'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'intro' => ['type' => 'string'],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['heading', 'body', 'facts_used'],
                        'properties' => [
                            'heading' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'facts_used' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'sentence_fact_map' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'sentence_text',
                            'sentence_type',
                            'approved_fact_ids',
                            'approved_inference_id',
                            'claim_status',
                            'attribution_in_sentence',
                            'blocked_fact_hit',
                            'source_similarity_score',
                            'fact_sheet_similarity_score',
                        ],
                        'properties' => [
                            'sentence_text' => ['type' => 'string'],
                            'sentence_type' => ['type' => 'string', 'enum' => ['fact', 'attributed_claim', 'denial', 'context', 'inference', 'transition']],
                            'approved_fact_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'approved_inference_id' => ['type' => 'string'],
                            'claim_status' => ['type' => 'string'],
                            'attribution_in_sentence' => ['type' => 'boolean'],
                            'blocked_fact_hit' => ['type' => 'boolean'],
                            'source_similarity_score' => ['type' => 'number'],
                            'fact_sheet_similarity_score' => ['type' => 'number'],
                        ],
                    ],
                ],
                'source_mentions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'editor_commentary_blocks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image_policy' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['allowed_images', 'rejected_images', 'notes'],
                    'properties' => [
                        'allowed_images' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'rejected_images' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'notes' => ['type' => 'string'],
                    ],
                ],
                'confidence' => ['type' => 'number'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['passed', 'risk_level', 'issues', 'required_actions', 'image_rights_passed', 'confidence'],
            'properties' => [
                'passed' => ['type' => 'boolean'],
                'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['type', 'detail', 'severity'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['similarity', 'quote', 'image', 'attribution', 'fact']],
                            'detail' => ['type' => 'string'],
                            'severity' => ['type' => 'string'],
                        ],
                    ],
                ],
                'required_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image_rights_passed' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiOptions(string $module, string $schemaName, string $schemaVersion): array
    {
        $stage = $this->aiStageForSchema($schemaName);
        $providerFallback = config('core.ai_structured_extraction.provider', 'agentic_ai');
        $modelFallback = config('core.ai_structured_extraction.model', 'gpt-5.6-luna');

        return [
            'module' => $module,
            // The admin AI runtime rows are seeded under the copyright_safe_news_* task keys; using the schema name
            // here meant those rows were never read and admin changes had no effect.
            'task' => $this->aiTaskKeyForStage($stage) ?? $schemaName,
            'schema_name' => $schemaName,
            'schema_version' => $schemaVersion,
            'stage' => $stage,
            'cache_enabled' => (bool) config('core.news_compliance.ai.cache_enabled', false),
            'provider' => (string) $this->aiStageConfig($stage, 'provider', $providerFallback),
            'model' => (string) $this->aiStageConfig($stage, 'model', $modelFallback),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiRunLog(AiStructuredExtractionResult $result, string $schemaName): array
    {
        $stage = $this->aiStageForSchema($schemaName);

        return [
            ...$result->toArray(),
            'stage' => $stage,
            'provider' => (string) $this->aiStageConfig(
                $stage,
                'provider',
                config('core.ai_structured_extraction.provider', 'agentic_ai'),
            ),
        ];
    }

    /**
     * Admin AI runtime task key of a pipeline stage (see AiRuntimeSettingSeeder).
     *
     * @param string $stage `fact_sheet`, `draft` or `review`.
     * @return string|null
     */
    private function aiTaskKeyForStage(string $stage): ?string
    {
        return match ($stage) {
            'fact_sheet' => 'copyright_safe_news_fact_extraction',
            'draft' => 'copyright_safe_news_drafting',
            'review' => 'copyright_safe_news_legal_editorial_review',
            default => null,
        };
    }

    private function aiStageForSchema(string $schemaName): string
    {
        return match ($schemaName) {
            'core_news_compliance_fact_sheet' => 'fact_sheet',
            'core_news_compliance_draft' => 'draft',
            'core_news_compliance_review' => 'review',
            default => 'default',
        };
    }

    private function aiStageConfig(string $stage, string $key, mixed $fallback): mixed
    {
        return config(
            "core.news_compliance.ai.stages.{$stage}.{$key}",
            config("core.news_compliance.ai.{$key}", $fallback),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function excludedHosts(NewsSourceDocument $seed, array $input): array
    {
        $hosts = [
            $seed->host(),
            ...array_map(
                static fn (mixed $host): string => Str::lower(trim((string) $host)),
                (array) config('core.news_compliance.discovery.excluded_hosts', []),
            ),
            ...array_map(
                static fn (mixed $host): string => Str::lower(trim((string) $host)),
                (array) ($input['excluded_hosts'] ?? []),
            ),
        ];

        return array_values(array_filter(array_unique($hosts), static fn (string $host): bool => $host !== ''));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function discoveryEnabled(array $input): bool
    {
        if (array_key_exists('discovery', $input) && is_array($input['discovery']) && array_key_exists('enabled', $input['discovery'])) {
            return (bool) $input['discovery']['enabled'];
        }

        return (bool) ($input['discover_related'] ?? config('core.news_compliance.discovery.enabled', true));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function minimumRelatedSourcesForAutoPass(array $input): int
    {
        foreach (['min_related_sources_for_auto_pass', 'minimum_related_sources'] as $key) {
            if (array_key_exists($key, $input) && is_numeric($input[$key])) {
                return max(0, (int) $input[$key]);
            }
        }

        return max(0, (int) config('core.news_compliance.discovery.min_related_sources_for_auto_pass', 1));
    }

    /**
     * @param list<string> $excludedHosts
     */
    private function searchResultAllowed(NewsSearchResult $result, NewsSourceDocument $seed, array $excludedHosts): bool
    {
        $url = $this->normalizedUrl($result->url);
        if ($url === '' || $url === $this->normalizedUrl($seed->url)) {
            return false;
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($excludedHosts as $excludedHost) {
            if ($host === $excludedHost || str_ends_with($host, '.' . $excludedHost)) {
                return false;
            }
        }

        return true;
    }

    private function searchDelay(): void
    {
        $delayMs = max(0, (int) config('core.news_compliance.discovery.request_delay_milliseconds', 900));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function normalizedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return rtrim($scheme . '://' . $host . $path, '/') . $query;
    }

    /**
     * @param array<string, mixed> $factSheet
     * @return array<string, mixed>
     */
    private function verifiedFactSheet(array $factSheet, array $input = []): array
    {
        $high = $this->sanitizeFactList($this->stringList((array) ($factSheet['facts_high'] ?? [])));
        $medium = $this->sanitizeFactList($this->stringList((array) ($factSheet['facts_medium'] ?? [])));
        $manualReviewItems = $this->sanitizeFactList($this->stringList((array) ($factSheet['manual_review_items'] ?? [])));
        $promotionNotes = [];
        $sanitizedFacts = [];
        $sanitizedNormalizedFacts = [];

        foreach ((array) ($factSheet['facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $text = trim((string) ($fact['text'] ?? ''));
            $sanitizedFacts[] = [
                ...$fact,
                'text' => $this->sanitizeFactText($text),
                'raw_text_hash' => $text === '' ? '' : hash('sha256', $text),
                'forbidden_phrases' => $this->forbiddenPhrasesForFact($text),
                'claim_status' => $this->claimStatusForFact($fact, $manualReviewItems),
                'attribution_required' => $this->attributionForFact($fact),
            ];
        }

        foreach ((array) ($factSheet['normalized_facts'] ?? []) as $normalizedFact) {
            if (! is_array($normalizedFact)) {
                continue;
            }

            $canonicalFact = $this->sanitizeFactText((string) ($normalizedFact['canonical_fact'] ?? ''));
            $sanitizedNormalizedFacts[] = [
                ...$normalizedFact,
                'canonical_fact' => $canonicalFact,
            ];
            if ($canonicalFact === ''
                || (bool) ($normalizedFact['manual_review'] ?? false)
                || (array) ($normalizedFact['contradicting_sources'] ?? []) !== []
                || $this->matchesManualReviewItem($canonicalFact, $manualReviewItems)) {
                continue;
            }

            $confidence = max(0.0, min(1.0, (float) ($normalizedFact['confidence'] ?? 0.0)));
            $supportingSourceCount = count(array_values(array_filter((array) ($normalizedFact['supporting_sources'] ?? []))));

            if ($supportingSourceCount >= 2 && $confidence >= 0.8) {
                $high[] = $canonicalFact;
                $promotionNotes[] = 'Promoted normalized fact to facts_high because it has at least two supporting sources.';
                continue;
            }

            if ($confidence >= 0.7) {
                $medium[] = $canonicalFact;
                $promotionNotes[] = 'Promoted normalized fact to facts_medium because it is non-conflicting and above confidence threshold.';
            }
        }

        foreach ($sanitizedFacts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $text = trim((string) ($fact['text'] ?? ''));
            if ($text === ''
                || (bool) ($fact['is_opinion'] ?? false)
                || (bool) ($fact['needs_manual_review'] ?? false)
                || $this->matchesManualReviewItem($text, $manualReviewItems)) {
                continue;
            }

            $confidence = max(0.0, min(1.0, (float) ($fact['confidence'] ?? 0.0)));
            if ($confidence < 0.75 || $this->factAlreadyListed($text, [...$high, ...$medium])) {
                continue;
            }

            $medium[] = $text;
            $promotionNotes[] = 'Promoted atomic fact to facts_medium because it is non-opinion, not marked for review, and above confidence threshold.';
        }

        $high = $this->uniqueFactTexts($high);
        $medium = array_values(array_filter(
            $this->uniqueFactTexts($medium),
            fn (string $fact): bool => ! $this->factAlreadyListed($fact, $high),
        ));
        $draftExcludedFacts = $this->draftExcludedFacts([...$high, ...$medium], $sanitizedFacts, $manualReviewItems);
        $high = array_values(array_filter(
            $high,
            fn (string $fact): bool => ! $this->factAlreadyListed($fact, $draftExcludedFacts),
        ));
        $medium = array_values(array_filter(
            $medium,
            fn (string $fact): bool => ! $this->factAlreadyListed($fact, $draftExcludedFacts),
        ));

        return [
            ...$factSheet,
            'facts' => $sanitizedFacts,
            'normalized_facts' => $sanitizedNormalizedFacts,
            'facts_high' => $high,
            'facts_medium' => $medium,
            'facts_low' => $this->sanitizeFactList($this->stringList((array) ($factSheet['facts_low'] ?? []))),
            'manual_review_items' => $manualReviewItems,
            'draft_excluded_facts' => $draftExcludedFacts,
            'source_wording_scrubbed' => true,
            'claim_status' => 'fact_sheet_sanitized_for_independent_drafting',
            'drafting_packet' => $this->draftingPacket([
                ...$factSheet,
                'facts' => $sanitizedFacts,
                'facts_high' => $high,
                'facts_medium' => $medium,
                'draft_excluded_facts' => $draftExcludedFacts,
                'manual_review_items' => $manualReviewItems,
            ], $input),
            'verification_notes' => array_values(array_unique([
                ...(array) ($factSheet['verification_notes'] ?? []),
                ...$promotionNotes,
            ])),
        ];
    }

    /**
     * @param list<string> $facts
     * @return list<string>
     */
    private function sanitizeFactList(array $facts): array
    {
        return array_values(array_filter(array_map(
            fn (string $fact): string => $this->sanitizeFactText($fact),
            $facts,
        ), static fn (string $fact): bool => $fact !== ''));
    }

    private function sanitizeFactText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $text = str_replace(['“', '”', '‘', '’', '"', "'"], '', $text);
        $text = preg_replace(
            '/theo dõi,\s*(?:giám sát,\s*)?quấy rối và khiêu khích[^.,;]{0,80}?ở cự ly gần/iu',
            'áp sát hoặc theo dõi nhóm lực lượng theo cáo buộc của một bên',
            $text,
        ) ?? $text;
        foreach ($this->sourceLikePhraseReplacements() as $phrase => $replacement) {
            $text = str_ireplace($phrase, $replacement, $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return array<string, string>
     */
    private function sourceLikePhraseReplacements(): array
    {
        return [
            'theo dõi, giám sát, quấy rối và khiêu khích ở cự ly gần' => 'áp sát hoặc theo dõi nhóm lực lượng theo cáo buộc của một bên',
            'theo dõi, quấy rối và khiêu khích ở cự ly gần' => 'áp sát hoặc theo dõi nhóm lực lượng theo cáo buộc của một bên',
            'theo dõi, quấy rối và khiêu khích' => 'áp sát hoặc theo dõi theo cáo buộc của một bên',
            'thông tin sai sự thật' => 'bác bỏ cáo buộc',
            'vô căn cứ' => 'cho rằng cáo buộc thiếu cơ sở',
            'an toàn là ưu tiên hàng đầu' => 'nhấn mạnh yêu cầu bảo đảm an toàn',
            'chuyên nghiệp và ổn định' => 'mô tả hoạt động là theo quy trình',
            'chuyên nghiệp, tận tâm' => 'theo quy trình',
            'bằng chứng' => 'tài liệu do bên nêu cáo buộc công bố',
            'sự thật được xác minh' => 'những điểm đã được đối chiếu',
        ];
    }

    /**
     * @param array<string, mixed> $factSheet
     * @return array<string, mixed>
     */
    private function draftSafeFactSheet(array $factSheet): array
    {
        return [
            'topic' => (string) ($factSheet['topic'] ?? ''),
            'confidence' => max(0.0, min(1.0, (float) ($factSheet['confidence'] ?? 0.0))),
            'source_wording_scrubbed' => (bool) ($factSheet['source_wording_scrubbed'] ?? false),
            'claim_status' => (string) ($factSheet['claim_status'] ?? ''),
            'article_profile' => (array) ($factSheet['article_profile'] ?? []),
            'sources' => array_map(static fn (mixed $source): array => [
                'url' => (string) data_get($source, 'url', ''),
                'publisher' => (string) data_get($source, 'publisher', ''),
                'published_at' => (string) data_get($source, 'published_at', ''),
                'source_type' => (string) data_get($source, 'source_type', ''),
                'independence_score' => max(0.0, min(1.0, (float) data_get($source, 'independence_score', 0.0))),
            ], (array) ($factSheet['sources'] ?? [])),
            'approved_fact_count' => count((array) data_get($factSheet, 'drafting_packet.approved_facts', [])),
            'blocked_fact_counts' => [
                'facts_low' => count((array) ($factSheet['facts_low'] ?? [])),
                'manual_review_items' => count((array) ($factSheet['manual_review_items'] ?? [])),
                'draft_excluded_facts' => count((array) ($factSheet['draft_excluded_facts'] ?? [])),
            ],
            'drafting_policy' => 'Fact prose and blocked fact text are withheld from the drafting model. Use only drafting_packet.structured_fact_records.',
        ];
    }

    /**
     * @param array<string, mixed> $factSheet
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function draftingPacket(array $factSheet, array $input): array
    {
        $approvedFacts = [];
        $approvedTexts = [
            ...$this->stringList((array) ($factSheet['facts_high'] ?? [])),
            ...$this->stringList((array) ($factSheet['facts_medium'] ?? [])),
        ];

        foreach ((array) ($factSheet['facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $text = trim((string) ($fact['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $isApproved = false;
            foreach ($approvedTexts as $approvedText) {
                if ($this->tokenSimilarity($text, $approvedText) >= 0.9) {
                    $isApproved = true;
                    break;
                }
            }

            if (! $isApproved) {
                continue;
            }

            $approvedFacts[] = [
                'id' => trim((string) ($fact['id'] ?? '')),
                'claim_status' => (string) ($fact['claim_status'] ?? $this->claimStatusForFact($fact, (array) ($factSheet['manual_review_items'] ?? []))),
                'claim_actor' => $this->claimActorForFact($fact),
                'publication_source' => $this->publicationSourceForFact($fact),
                'source_chain' => $this->sourceChainForFact($fact),
                'public_attribution' => $this->publicAttributionForFact($fact),
                'claim_truth_status' => $this->claimTruthStatusForFact($fact),
                'semantic_slots' => $this->semanticSlotsForFact($fact),
                'forbidden_phrases' => $this->stringList((array) ($fact['forbidden_phrases'] ?? $this->forbiddenPhrasesForFact($text))),
                'source_url' => (string) ($fact['source_url'] ?? ''),
                'confidence' => max(0.0, min(1.0, (float) ($fact['confidence'] ?? 0.0))),
            ];
        }

        return [
            'mode' => 'independent_article',
            'pipeline_profile' => (array) ($factSheet['article_profile'] ?? []),
            'angle_plan' => $this->independentAnglePlan($input),
            'approved_facts' => $approvedFacts,
            'structured_fact_records' => $approvedFacts,
            'blocked_fact_policy' => [
                'text_withheld_from_drafter' => true,
                'public_body_rule' => 'Do not mention blocked, excluded, low-confidence, or manual-review facts in the public article body.',
                'counts' => [
                    'facts_low' => count((array) ($factSheet['facts_low'] ?? [])),
                    'manual_review_items' => count((array) ($factSheet['manual_review_items'] ?? [])),
                    'draft_excluded_facts' => count((array) ($factSheet['draft_excluded_facts'] ?? [])),
                ],
            ],
            'blocked_source_phrases' => [
                'Source-like allegation, denial, proof/truth, and rhetorical phrases are withheld or listed as forbidden_phrases on fact records. Do not restore them.',
            ],
            'claim_rules' => [
                'verified_fact' => 'May be written as fact when supported by approved facts.',
                'official_claim' => 'Attribute in the same sentence and do not assert as true.',
                'denial' => 'Attribute in the same sentence and present as a response, not independent truth.',
                'unverified_detail' => 'Use only with explicit uncertainty or request editor check.',
                'separate_background_event' => 'Exclude from main body unless angle_plan marks it approved context.',
            ],
            'headline_policy' => 'Use a distinct angle and low lexical overlap with source headlines. Avoid proof/truth wording unless claim truth is independently verified.',
            'sentence_fact_map_policy' => 'Every public intro/body sentence must map to approved_fact_ids or an approved_inference_id; transition sentences may have empty ids only when they add no factual claim.',
        ];
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<string, mixed>
     */
    private function semanticSlotsForFact(array $fact): array
    {
        $text = trim((string) ($fact['text'] ?? ''));
        $actor = $this->claimActorForFact($fact);
        $action = $this->factActionForText($text);
        $object = $this->factObjectForText($text, $actor, $action);

        return [
            'actor' => $actor,
            'action' => $action,
            'object' => $object,
            'date_or_time' => $this->dateOrTimeForText($text),
            'location' => $this->locationForText($text),
            'keywords' => array_slice($this->tokenSet($text), 0, 18),
            'not_public_sentence' => true,
        ];
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function claimActorForFact(array $fact): string
    {
        $text = trim((string) ($fact['text'] ?? ''));
        if ($text === '') {
            return 'Unspecified actor';
        }

        if (preg_match('/^(.{2,80}?)\s+(?:cho biết|cho hay|nói|nói rằng|tuyên bố|thông báo|cáo buộc|bác bỏ|phủ nhận|cho rằng|khẳng định)\b/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim(Str::limit($text, 70, ''));
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function publicationSourceForFact(array $fact): string
    {
        $host = parse_url((string) ($fact['source_url'] ?? ''), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $sourceTitle = trim((string) ($fact['source_title'] ?? ''));

        return $sourceTitle !== '' ? $sourceTitle : 'Unspecified publication source';
    }

    /**
     * @param array<string, mixed> $fact
     * @return list<string>
     */
    private function sourceChainForFact(array $fact): array
    {
        return array_values(array_filter([
            $this->publicationSourceForFact($fact),
            trim((string) ($fact['source_url'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function publicAttributionForFact(array $fact): string
    {
        $actor = $this->claimActorForFact($fact);
        $publication = $this->publicationSourceForFact($fact);
        $status = (string) ($fact['claim_status'] ?? $this->claimStatusForFact($fact, []));

        if (in_array($status, ['official_claim', 'denial', 'unverified_detail'], true)) {
            return sprintf('Attribute to %s; publication source: %s.', $actor, $publication);
        }

        return sprintf('Publication source: %s.', $publication);
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function claimTruthStatusForFact(array $fact): string
    {
        return match ((string) ($fact['claim_status'] ?? $this->claimStatusForFact($fact, []))) {
            'official_claim' => 'claim existence verified; claim truth not independently verified',
            'denial' => 'denial existence verified; denial truth not independently verified',
            'unverified_detail' => 'not available for public factual assertion',
            'separate_background_event' => 'background only; exclude unless explicitly approved',
            default => 'verified fact within approved source packet',
        };
    }

    private function factActionForText(string $text): string
    {
        $normalized = $this->normalizedTextForReview($text);
        foreach ([
            'cao buoc' => 'alleges',
            'bac bo' => 'denies',
            'phu nhan' => 'denies',
            'thong bao' => 'announces',
            'tuyen bo' => 'states',
            'cho rang' => 'claims',
            'cho hay' => 'reports',
            'cho biet' => 'reports',
            'noi rang' => 'says',
            'khang dinh' => 'asserts',
        ] as $needle => $action) {
            if (str_contains($normalized, $needle)) {
                return $action;
            }
        }

        return 'records';
    }

    private function factObjectForText(string $text, string $actor, string $action): string
    {
        $object = trim($text);
        if ($actor !== '' && str_starts_with(Str::lower($object), Str::lower($actor))) {
            $object = trim(mb_substr($object, mb_strlen($actor)));
        }

        $object = preg_replace('/^(?:cho biết|cho hay|nói|nói rằng|tuyên bố|thông báo|cáo buộc|bác bỏ|phủ nhận|cho rằng|khẳng định)\b\s*/iu', '', $object) ?? $object;
        $object = trim($object, " \t\n\r\0\x0B.,;:-");
        foreach ($this->sourceLikePhraseReplacements() as $replacement) {
            if ($replacement !== '') {
                $object = str_ireplace($replacement, 'disputed close-monitoring allegation', $object);
            }
        }

        if ($object === '' || $object === $text) {
            return implode('; ', array_slice($this->tokenSet($text), 0, 12));
        }

        return Str::limit($object, 180, '');
    }

    private function dateOrTimeForText(string $text): string
    {
        if (preg_match('/\b(?:ngày\s*)?\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/u', $text, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}\s*-\s*\d{1,2}\b/u', $text, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b(?:tháng|thang)\s+\d{1,2}\b/iu', $text, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    private function locationForText(string $text): string
    {
        if (preg_match('/\b(?:tại|ở|quanh|gần)\s+([A-ZĐ][\p{L}\p{M}\s-]{2,60})/u', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function forbiddenPhrasesForFact(string $text): array
    {
        $normalized = $this->normalizedTextForReview($text);
        $phrases = [];

        foreach (array_keys($this->sourceLikePhraseReplacements()) as $phrase) {
            $phraseNormalized = $this->normalizedTextForReview($phrase);
            if ($phraseNormalized !== '' && str_contains($normalized, $phraseNormalized)) {
                $phrases[] = 'source_like_phrase_hash:' . substr(hash('sha256', $phrase), 0, 16);
            }
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function independentAnglePlan(array $input): array
    {
        $editorialAngle = trim((string) ($input['editorial_angle'] ?? ''));

        return [
            'selected_angle' => $editorialAngle !== ''
                ? $editorialAngle
                : 'Explain what is verified, what is claimed, what is denied, and what remains unverified through a structure different from the seed source.',
            'reject_frames' => [
                'Country A accused Country B; Country B denied it.',
                'Source headline followed by source lead and source paragraph order.',
                'A recap that relies on source-specific quote phrases as section anchors.',
            ],
            'suggested_structure' => [
                'What can be independently stated from approved facts.',
                'How each side described the disputed action with same-sentence attribution.',
                'Which details remain unverified or excluded.',
                'Why the verified context matters without adding separate unapproved incidents.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fact
     * @param array<int, mixed> $manualReviewItems
     */
    private function claimStatusForFact(array $fact, array $manualReviewItems): string
    {
        $text = $this->normalizedTextForReview((string) ($fact['text'] ?? ''));
        $confidence = max(0.0, min(1.0, (float) ($fact['confidence'] ?? 0.0)));

        if ((bool) ($fact['needs_manual_review'] ?? false)
            || $confidence < 0.7
            || $this->matchesManualReviewItem((string) ($fact['text'] ?? ''), $this->stringList($manualReviewItems))) {
            return 'unverified_detail';
        }

        if (str_contains($text, 'thang 12') || str_contains($text, 'truoc do') || str_contains($text, 'vu viec khac')) {
            return 'separate_background_event';
        }

        if (str_contains($text, 'bac bo') || str_contains($text, 'phu nhan')) {
            return 'denial';
        }

        if ((string) ($fact['fact_type'] ?? '') === 'claim'
            || str_contains($text, 'cao buoc')
            || str_contains($text, 'tuyen bo')
            || str_contains($text, 'cho rang')
            || str_contains($text, 'noi rang')
            || str_contains($text, 'khang dinh')) {
            return 'official_claim';
        }

        if (str_contains($text, 'video') || str_contains($text, 'tai lieu do ben neu cao buoc cong bo')) {
            return 'unverified_detail';
        }

        return 'verified_fact';
    }

    /**
     * @param list<string> $candidateFacts
     * @param list<array<string, mixed>> $atomicFacts
     * @param list<string> $manualReviewItems
     * @return list<string>
     */
    private function draftExcludedFacts(array $candidateFacts, array $atomicFacts, array $manualReviewItems): array
    {
        $excluded = [];

        foreach ($candidateFacts as $candidateFact) {
            if ($this->matchesManualReviewItem($candidateFact, $manualReviewItems)) {
                $excluded[] = $candidateFact;
                continue;
            }

            foreach ($atomicFacts as $atomicFact) {
                $text = trim((string) ($atomicFact['text'] ?? ''));
                if ($text === '' || $this->tokenSimilarity($candidateFact, $text) < 0.82) {
                    continue;
                }

                $status = (string) ($atomicFact['claim_status'] ?? '');
                $confidence = max(0.0, min(1.0, (float) ($atomicFact['confidence'] ?? 0.0)));
                if ($confidence < 0.7 || in_array($status, ['unverified_detail', 'separate_background_event'], true)) {
                    $excluded[] = $candidateFact;
                }

                break;
            }
        }

        return $this->uniqueFactTexts($excluded);
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function attributionForFact(array $fact): string
    {
        $sourceTitle = trim((string) ($fact['source_title'] ?? ''));
        $sourceUrl = trim((string) ($fact['source_url'] ?? ''));

        if ($sourceTitle !== '') {
            return $sourceTitle;
        }

        return $sourceUrl !== '' ? $sourceUrl : 'Specific source attribution required';
    }

    /**
     * @param array<string, mixed> $factSheet
     * @param array<string, mixed> $draft
     * @param list<NewsSourceDocument> $documents
     * @return array<string, mixed>
     */
    private function mechanicalReview(array $factSheet, array $draft, array $documents, array $input = []): array
    {
        $draftText = $this->draftPlainText($draft);
        $publicBodyText = $this->draftPublicBodyText($draft);
        $draftWordCount = $this->draftWordCount($draft);
        $minimumDraftWords = $this->minimumDraftWords($input);
        $draftSentences = $this->sentenceList($draftText);
        $publicBodySentences = $this->sentenceList($publicBodyText);
        $draftHeadline = $this->draftHeadline($draft);
        $draftNormalized = $this->normalizedTextForReview($publicBodyText);
        $approvedFacts = $this->approvedFactReferences($factSheet);
        $thresholds = $this->mechanicalReviewThresholds($factSheet);

        $bestHeadline = [
            'score' => 0.0,
            'source_url' => '',
            'source_title_hash' => '',
        ];
        $bestSentence = [
            'score' => 0.0,
            'draft_sentence_excerpt' => '',
            'source_sentence_hash' => '',
            'source_url' => '',
            'approved_fact_similarity' => 0.0,
        ];

        foreach ($documents as $document) {
            if ($draftHeadline !== '' && $document->title !== '') {
                $headlineScore = $this->tokenSimilarity($draftHeadline, $document->title);
                if ($headlineScore > $bestHeadline['score']) {
                    $bestHeadline = [
                        'score' => $headlineScore,
                        'source_url' => $document->url,
                        'source_title_hash' => hash('sha256', $document->title),
                    ];
                }
            }

            foreach ($this->sentenceList($this->documentPlainText($document)) as $sourceSentence) {
                foreach ($draftSentences as $draftSentence) {
                    $score = $this->tokenSimilarity($draftSentence['normalized'], $sourceSentence['normalized']);
                    if ($score > $bestSentence['score']) {
                        $approvedFactSimilarity = $this->bestApprovedFactSimilarity($draftSentence['normalized'], $approvedFacts);
                        $bestSentence = [
                            'score' => $score,
                            'draft_sentence_excerpt' => Str::limit($draftSentence['raw'], 180, ''),
                            'source_sentence_hash' => hash('sha256', $sourceSentence['raw']),
                            'source_url' => $document->url,
                            'approved_fact_similarity' => $approvedFactSimilarity,
                        ];
                    }
                }
            }
        }

        $draftFacts = $this->draftFactsUsed($draft);
        $unsupportedDraftFacts = $this->unsupportedDraftFacts($draftFacts, $approvedFacts);
        $manualReviewHits = $this->manualReviewItemsInDraft((array) ($factSheet['manual_review_items'] ?? []), $draftNormalized, $publicBodySentences);
        $sourcePhraseHits = $this->sourceLikePhraseHits($draftText);
        $factSheetWording = $this->bestApprovedFactSentenceMatch($draftSentences, $approvedFacts);
        $sentenceMapReview = $this->sentenceFactMapReview($draft, $publicBodySentences, $approvedFacts);
        $blockedFactHits = $this->blockedFactsInPublicBody($factSheet, $draftNormalized, $publicBodySentences);

        $issues = [];
        $requiredActions = [];

        if ($bestHeadline['score'] >= $thresholds['headline_high']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft headline is too close to a source headline (token overlap %.2f).', $bestHeadline['score']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Create a new headline that does not mirror source headlines.';
        } elseif ($bestHeadline['score'] >= $thresholds['headline_medium']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft headline has elevated overlap with a source headline (token overlap %.2f).', $bestHeadline['score']),
                'severity' => 'medium',
            ];
            $requiredActions[] = 'Revise headline wording before publishing.';
        }

        if ($bestSentence['score'] >= $thresholds['sentence_high']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft sentence is too close to source wording (token overlap %.2f; approved fact overlap %.2f; source=%s).', $bestSentence['score'], $bestSentence['approved_fact_similarity'], $bestSentence['source_url']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Rewrite the affected sentence from verified facts instead of source phrasing.';
        } elseif ($bestSentence['score'] >= $thresholds['sentence_medium']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft sentence has elevated overlap with source wording (token overlap %.2f; source=%s).', $bestSentence['score'], $bestSentence['source_url']),
                'severity' => 'medium',
            ];
            $requiredActions[] = 'Review sentence-level wording before publishing.';
        }

        if ($factSheetWording['score'] >= $thresholds['fact_sheet_sentence_high']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft sentence is too close to approved fact wording (token overlap %.2f).', $factSheetWording['score']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Rewrite from the sanitized fact packet instead of copying fact-sheet wording.';
        } elseif ($factSheetWording['score'] >= $thresholds['fact_sheet_sentence_medium']) {
            $issues[] = [
                'type' => 'similarity',
                'detail' => sprintf('Draft sentence has elevated overlap with approved fact wording (token overlap %.2f).', $factSheetWording['score']),
                'severity' => 'medium',
            ];
            $requiredActions[] = 'Review fact-sheet wording overlap before publishing.';
        }

        if ($unsupportedDraftFacts !== []) {
            $issues[] = [
                'type' => 'fact',
                'detail' => sprintf('Draft uses %d fact marker(s) not present in facts_high or facts_medium.', count($unsupportedDraftFacts)),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Remove unsupported facts or approve them into facts_medium after editor review.';
        }

        if ($manualReviewHits > 0) {
            $issues[] = [
                'type' => 'quote',
                'detail' => sprintf('Draft contains %d item(s) that the fact sheet marked for manual review.', $manualReviewHits),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Remove manual-review items from the draft unless an editor explicitly approves them.';
        }

        if ($sourcePhraseHits !== []) {
            $issues[] = [
                'type' => 'quote',
                'detail' => 'Draft contains source-like phrase(s): ' . implode('; ', $sourcePhraseHits),
                'severity' => $this->sourcePhraseSeverity($sourcePhraseHits),
            ];
            $requiredActions[] = 'Remove source-like phrases or convert necessary wording into short attributed quotes.';
        }

        if ($sentenceMapReview['unmapped_public_sentence_count'] > 0) {
            $issues[] = [
                'type' => 'fact',
                'detail' => sprintf('Draft has %d public sentence(s) without approved fact or inference support.', $sentenceMapReview['unmapped_public_sentence_count']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Add sentence-level approved fact mapping or remove unsupported public sentences.';
        }

        if ($sentenceMapReview['unsupported_sentence_fact_id_count'] > 0) {
            $issues[] = [
                'type' => 'fact',
                'detail' => sprintf('Sentence fact map references %d unsupported fact id(s).', $sentenceMapReview['unsupported_sentence_fact_id_count']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Use only approved fact IDs in sentence_fact_map.';
        }

        if ($sentenceMapReview['attribution_missing_count'] > 0) {
            $issues[] = [
                'type' => 'attribution',
                'detail' => sprintf('Sentence fact map marks %d contested claim sentence(s) without same-sentence attribution.', $sentenceMapReview['attribution_missing_count']),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Add same-sentence attribution for contested claims and denials.';
        }

        if ($sentenceMapReview['blocked_fact_hit_count'] > 0 || $blockedFactHits['count'] > 0) {
            $issues[] = [
                'type' => 'fact',
                'detail' => sprintf('Draft public body contains or declares %d blocked/excluded fact hit(s).', max($sentenceMapReview['blocked_fact_hit_count'], $blockedFactHits['count'])),
                'severity' => 'high',
            ];
            $requiredActions[] = 'Remove blocked, excluded, low-confidence, and manual-review facts from public article body.';
        }

        if ($minimumDraftWords > 0 && $draftWordCount < $minimumDraftWords) {
            $issues[] = [
                'type' => 'fact',
                'detail' => sprintf('Draft is too short for the requested article depth (%d words; requires at least %d).', $draftWordCount, $minimumDraftWords),
                'severity' => $draftWordCount < (int) floor($minimumDraftWords * 0.75) ? 'high' : 'medium',
            ];
            $requiredActions[] = 'Expand the draft into a complete article that meets the requested minimum word count.';
        }

        $riskLevel = 'low';
        foreach ($issues as $issue) {
            $riskLevel = $this->highestRiskLevel($riskLevel, (string) ($issue['severity'] ?? 'low'));
        }

        return [
            'passed' => $issues === [],
            'risk_level' => $riskLevel,
            'metrics' => [
                'article_profile' => (string) data_get($factSheet, 'article_profile.selected_profile', 'general_news'),
                'similarity_threshold_level' => (string) data_get($factSheet, 'article_profile.similarity_threshold_level', 'strict'),
                'headline_similarity' => round((float) $bestHeadline['score'], 4),
                'headline_source_url' => $bestHeadline['source_url'],
                'headline_source_title_hash' => $bestHeadline['source_title_hash'],
                'max_sentence_similarity' => round((float) $bestSentence['score'], 4),
                'max_sentence_source_url' => $bestSentence['source_url'],
                'max_sentence_source_hash' => $bestSentence['source_sentence_hash'],
                'max_sentence_draft_excerpt' => $bestSentence['draft_sentence_excerpt'],
                'max_sentence_approved_fact_similarity' => round((float) $bestSentence['approved_fact_similarity'], 4),
                'max_fact_sheet_sentence_similarity' => round((float) $factSheetWording['score'], 4),
                'max_fact_sheet_sentence_excerpt' => $factSheetWording['draft_sentence_excerpt'],
                'approved_fact_count' => count($approvedFacts['texts']) + count($approvedFacts['ids']),
                'draft_fact_marker_count' => count($draftFacts),
                'unsupported_draft_fact_count' => count($unsupportedDraftFacts),
                'manual_review_item_in_draft_count' => $manualReviewHits,
                'source_like_phrase_hit_count' => count($sourcePhraseHits),
                'source_like_phrase_hits' => $sourcePhraseHits,
                'public_sentence_count' => count($publicBodySentences),
                'sentence_fact_map_count' => $sentenceMapReview['sentence_fact_map_count'],
                'unmapped_public_sentence_count' => $sentenceMapReview['unmapped_public_sentence_count'],
                'unsupported_sentence_fact_id_count' => $sentenceMapReview['unsupported_sentence_fact_id_count'],
                'sentence_map_attribution_missing_count' => $sentenceMapReview['attribution_missing_count'],
                'blocked_fact_in_public_body_count' => $blockedFactHits['count'],
                'blocked_fact_public_body_hashes' => $blockedFactHits['hashes'],
                'draft_word_count' => $draftWordCount,
                'minimum_draft_words' => $minimumDraftWords,
            ],
            'issues' => $issues,
            'required_actions' => array_values(array_unique($requiredActions)),
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $mechanicalReview
     * @return array<string, mixed>
     */
    private function applyMechanicalReview(array $review, array $mechanicalReview): array
    {
        $issues = (array) ($mechanicalReview['issues'] ?? []);
        if ($issues === []) {
            $review['mechanical_review_passed'] = true;

            return $review;
        }

        $review['risk_level'] = $this->highestRiskLevel(
            (string) ($review['risk_level'] ?? 'low'),
            (string) ($mechanicalReview['risk_level'] ?? 'low'),
        );
        $review['issues'] = [
            ...(array) ($review['issues'] ?? []),
            ...$issues,
        ];
        $review['required_actions'] = array_values(array_unique([
            ...(array) ($review['required_actions'] ?? []),
            ...(array) ($mechanicalReview['required_actions'] ?? []),
        ]));
        $review['mechanical_review_passed'] = (bool) ($mechanicalReview['passed'] ?? false);
        if (! (bool) ($mechanicalReview['passed'] ?? false)) {
            $review['passed'] = false;
        }

        return $review;
    }

    /**
     * @param array<string, mixed> $review
     * @param array<string, mixed> $mechanicalReview
     * @return array<string, mixed>
     */
    private function applyFinalGate(array $review, array $mechanicalReview): array
    {
        $issues = (array) ($review['issues'] ?? []);
        $hasHighIssue = false;
        foreach ($issues as $issue) {
            if (is_array($issue) && (string) ($issue['severity'] ?? '') === 'high') {
                $hasHighIssue = true;
                break;
            }
        }

        $modelPassed = (bool) ($review['model_review_passed'] ?? false);
        $mechanicalPassed = (bool) ($mechanicalReview['passed'] ?? false);
        $imageRightsPassed = (bool) ($review['image_rights_passed'] ?? false);
        $preGatePassed = (bool) ($review['passed'] ?? false);
        $passed = $preGatePassed && $modelPassed && $mechanicalPassed && $imageRightsPassed && ! $hasHighIssue;

        $review['final_gate'] = [
            'source_of_truth' => 'final_gate',
            'passed' => $passed,
            'model_review_passed' => $modelPassed,
            'mechanical_review_passed' => $mechanicalPassed,
            'image_rights_passed' => $imageRightsPassed,
            'high_severity_issue_present' => $hasHighIssue,
            'rule' => 'Final passed is true only when model review, mechanical review, image rights, source-count policy, and high-severity issue checks all pass.',
        ];
        $review['passed'] = $passed;

        return $review;
    }

    private function highestRiskLevel(string $left, string $right): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $left = $rank[$left] ?? 1;
        $right = $rank[$right] ?? 1;

        return array_search(max($left, $right), $rank, true) ?: 'low';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftHeadline(array $draft): string
    {
        return trim((string) ($draft['headline'] ?? ''));
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftPlainText(array $draft): string
    {
        $parts = [
            (string) ($draft['headline'] ?? ''),
            (string) ($draft['intro'] ?? ''),
        ];

        foreach ((array) ($draft['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $parts[] = (string) ($section['heading'] ?? '');
            $parts[] = (string) ($section['body'] ?? '');
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftPublicBodyText(array $draft): string
    {
        $parts = [
            (string) ($draft['intro'] ?? ''),
        ];

        foreach ((array) ($draft['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $parts[] = (string) ($section['body'] ?? '');
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function minimumDraftWords(array $input): int
    {
        if (isset($input['minimum_draft_words']) && is_numeric($input['minimum_draft_words'])) {
            return max(0, (int) $input['minimum_draft_words']);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rewriteOnIndependenceFailure(array $input): bool
    {
        if (array_key_exists('rewrite_on_independence_failure', $input)) {
            return (bool) $input['rewrite_on_independence_failure'];
        }

        return (bool) config('core.news_compliance.mechanical_review.rewrite_on_independence_failure', true);
    }

    /**
     * @param array<string, mixed> $mechanicalReview
     */
    private function mechanicalRevisionNote(array $mechanicalReview): string
    {
        $issues = array_map(
            static fn (mixed $issue): string => is_array($issue) ? (string) ($issue['detail'] ?? '') : '',
            (array) ($mechanicalReview['issues'] ?? []),
        );
        $issues = array_values(array_filter($issues, static fn (string $issue): bool => trim($issue) !== ''));

        return implode("\n", [
            'Previous draft failed independence review. Ignore the previous wording and draft again from the sanitized drafting_packet only.',
            'Use structured_fact_records only; do not copy or reconstruct approved fact prose.',
            'Use a headline with very low lexical overlap against source headlines; avoid the seed claim phrase, source actor-object cluster, and rhetorical "X hay Y?" dispute framing.',
            'No sentence may start from a fact card and merely add attribution. Build new sentences by combining or splitting approved fact records.',
            'Use only approved fact IDs in facts_used and sentence_fact_map. Do not use blocked, manual-review, low-confidence, excluded, or unsupported details.',
            'Do not mention blocked details in public body, even as excluded or unverified details.',
            'Every public intro/body sentence must have sentence_fact_map support via approved_fact_ids or approved_inference_id.',
            'Remove source-like quote phrases and proof/truth wording unless independently verified.',
            'Mechanical issues to fix: ' . implode(' | ', array_slice($issues, 0, 6)),
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftWordCount(array $draft): int
    {
        $normalized = Str::ascii($this->draftPlainText($draft));
        $words = preg_split('/\s+/u', trim($normalized)) ?: [];

        return count(array_filter($words, static fn (string $word): bool => preg_match('/[\p{L}\p{N}]/u', $word) === 1));
    }

    private function documentPlainText(NewsSourceDocument $document): string
    {
        return trim(implode("\n", array_filter([
            $document->summary,
            $document->content,
        ], static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @return list<array{raw: string, normalized: string}>
     */
    private function sentenceList(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?。！？])\s+|\R+/u', $text) ?: [];
        $sentences = [];
        foreach ($parts as $part) {
            $raw = trim((string) $part);
            $normalized = $this->normalizedTextForReview($raw);
            if ($raw === '' || mb_strlen($raw) < 35 || count($this->tokenSet($normalized)) < 5) {
                continue;
            }

            $sentences[] = [
                'raw' => $raw,
                'normalized' => $normalized,
            ];
        }

        return $sentences;
    }

    private function normalizedTextForReview(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^\p{L}\p{N}%]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function tokenSimilarity(string $left, string $right): float
    {
        $leftTokens = $this->tokenSet($left);
        $rightTokens = $this->tokenSet($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique([...$leftTokens, ...$rightTokens]));
        $jaccard = $intersection / max(1, $union);
        $containment = $intersection / max(1, min(count($leftTokens), count($rightTokens)));

        return max($jaccard, $containment);
    }

    /**
     * @return list<string>
     */
    private function tokenSet(string $value): array
    {
        $normalized = $this->normalizedTextForReview($value);
        if ($normalized === '') {
            return [];
        }

        $stopWords = [
            'ai', 'bi', 'bo', 'cac', 'cho', 'co', 'cua', 'da', 'dang', 'de', 'den', 'do', 'duoc', 'la', 'mot',
            'nam', 'nay', 'neu', 'nhung', 'o', 'sau', 'se', 'tai', 'thang', 'theo', 'thi', 'trong', 'tu', 'va',
            've', 'voi',
        ];

        return array_values(array_unique(array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 2 && ! in_array($token, $stopWords, true),
        )));
    }

    /**
     * @return array{headline_medium: float, headline_high: float, sentence_medium: float, sentence_high: float, fact_sheet_sentence_medium: float, fact_sheet_sentence_high: float}
     */
    private function mechanicalReviewThresholds(array $factSheet = []): array
    {
        $thresholds = [
            'headline_medium' => (float) config('core.news_compliance.mechanical_review.headline_medium_similarity', 0.45),
            'headline_high' => (float) config('core.news_compliance.mechanical_review.headline_high_similarity', 0.65),
            'sentence_medium' => (float) config('core.news_compliance.mechanical_review.sentence_medium_similarity', 0.75),
            'sentence_high' => (float) config('core.news_compliance.mechanical_review.sentence_high_similarity', 0.86),
            'fact_sheet_sentence_medium' => (float) config('core.news_compliance.mechanical_review.fact_sheet_sentence_medium_similarity', 0.9),
            'fact_sheet_sentence_high' => (float) config('core.news_compliance.mechanical_review.fact_sheet_sentence_high_similarity', 0.93),
        ];

        if ((string) data_get($factSheet, 'article_profile.similarity_threshold_level', 'strict') === 'medium') {
            $thresholds['headline_medium'] = max($thresholds['headline_medium'], 0.55);
            $thresholds['headline_high'] = max($thresholds['headline_high'], 0.75);
            $thresholds['sentence_medium'] = max($thresholds['sentence_medium'], 0.80);
            $thresholds['sentence_high'] = max($thresholds['sentence_high'], 0.90);
            $thresholds['fact_sheet_sentence_medium'] = max($thresholds['fact_sheet_sentence_medium'], 0.92);
            $thresholds['fact_sheet_sentence_high'] = max($thresholds['fact_sheet_sentence_high'], 0.96);
        }

        return $thresholds;
    }

    /**
     * @param list<array{raw: string, normalized: string}> $draftSentences
     * @param array{texts: list<string>, ids: list<string>} $approvedFacts
     * @return array{score: float, draft_sentence_excerpt: string}
     */
    private function bestApprovedFactSentenceMatch(array $draftSentences, array $approvedFacts): array
    {
        $best = [
            'score' => 0.0,
            'draft_sentence_excerpt' => '',
        ];

        foreach ($draftSentences as $draftSentence) {
            $score = $this->bestApprovedFactSimilarity($draftSentence['normalized'], $approvedFacts);
            if ($score > $best['score']) {
                $best = [
                    'score' => $score,
                    'draft_sentence_excerpt' => Str::limit($draftSentence['raw'], 180, ''),
                ];
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private function sourceLikePhraseHits(string $draftText): array
    {
        $normalized = $this->normalizedTextForReview($draftText);
        $hits = [];

        foreach (array_keys($this->sourceLikePhraseReplacements()) as $phrase) {
            $phraseNormalized = $this->normalizedTextForReview($phrase);
            if ($phraseNormalized !== '' && str_contains($normalized, $phraseNormalized)) {
                $hits[] = $phrase;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param list<string> $sourcePhraseHits
     */
    private function sourcePhraseSeverity(array $sourcePhraseHits): string
    {
        foreach ($sourcePhraseHits as $phrase) {
            if (count($this->tokenSet($phrase)) >= 7) {
                return 'high';
            }
        }

        return 'medium';
    }

    private function pipelineClaimStatus(bool $passed): string
    {
        return $passed ? 'approved_from_verified_fact_packet' : 'claims_require_editorial_or_legal_review';
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param list<string> $facts
     * @return list<string>
     */
    private function uniqueFactTexts(array $facts): array
    {
        $unique = [];
        foreach ($facts as $fact) {
            if (! $this->factAlreadyListed($fact, $unique)) {
                $unique[] = $fact;
            }
        }

        return $unique;
    }

    /**
     * @param list<string> $existingFacts
     */
    private function factAlreadyListed(string $fact, array $existingFacts): bool
    {
        foreach ($existingFacts as $existingFact) {
            if ($this->tokenSimilarity($fact, $existingFact) >= 0.9) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $manualReviewItems
     */
    private function matchesManualReviewItem(string $fact, array $manualReviewItems): bool
    {
        foreach ($manualReviewItems as $manualReviewItem) {
            if ($this->tokenSimilarity($fact, $manualReviewItem) >= 0.9) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $factSheet
     * @return array{texts: list<string>, ids: list<string>}
     */
    private function approvedFactReferences(array $factSheet): array
    {
        $texts = array_values(array_filter(array_map(
            static fn (mixed $fact): string => trim((string) $fact),
            [
                ...(array) ($factSheet['facts_high'] ?? []),
                ...(array) ($factSheet['facts_medium'] ?? []),
            ],
        )));
        $ids = [];

        foreach ((array) ($factSheet['facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $id = trim((string) ($fact['id'] ?? ''));
            $text = trim((string) ($fact['text'] ?? ''));
            if ($id === '' || $text === '') {
                continue;
            }

            foreach ($texts as $approvedText) {
                if ($this->tokenSimilarity($text, $approvedText) >= 0.9) {
                    $ids[] = $id;
                    break;
                }
            }
        }

        return [
            'texts' => $texts,
            'ids' => array_values(array_unique($ids)),
        ];
    }

    /**
     * @param array{texts: list<string>, ids: list<string>} $approvedFacts
     */
    private function bestApprovedFactSimilarity(string $sentence, array $approvedFacts): float
    {
        $best = 0.0;
        foreach ($approvedFacts['texts'] as $approvedFact) {
            $best = max($best, $this->tokenSimilarity($sentence, $approvedFact));
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<string>
     */
    private function draftFactsUsed(array $draft): array
    {
        $facts = [];
        foreach ((array) ($draft['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ((array) ($section['facts_used'] ?? []) as $fact) {
                $fact = trim((string) $fact);
                if ($fact !== '') {
                    $facts[] = $fact;
                }
            }
        }

        foreach ((array) ($draft['sentence_fact_map'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['approved_fact_ids'] ?? []) as $fact) {
                $fact = trim((string) $fact);
                if ($fact !== '') {
                    $facts[] = $fact;
                }
            }
        }

        return array_values(array_unique($facts));
    }

    /**
     * @param list<string> $draftFacts
     * @param array{texts: list<string>, ids: list<string>} $approvedFacts
     * @return list<string>
     */
    private function unsupportedDraftFacts(array $draftFacts, array $approvedFacts): array
    {
        if ($draftFacts === []) {
            return [];
        }

        if ($approvedFacts['texts'] === [] && $approvedFacts['ids'] === []) {
            return $draftFacts;
        }

        return array_values(array_filter($draftFacts, function (string $draftFact) use ($approvedFacts): bool {
            if (in_array($draftFact, $approvedFacts['ids'], true)) {
                return false;
            }

            foreach ($approvedFacts['texts'] as $approvedText) {
                if ($this->tokenSimilarity($draftFact, $approvedText) >= 0.78) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<array{raw: string, normalized: string}> $publicBodySentences
     * @param array{texts: list<string>, ids: list<string>} $approvedFacts
     * @return array{sentence_fact_map_count: int, unmapped_public_sentence_count: int, unsupported_sentence_fact_id_count: int, attribution_missing_count: int, blocked_fact_hit_count: int}
     */
    private function sentenceFactMapReview(array $draft, array $publicBodySentences, array $approvedFacts): array
    {
        $entries = [];
        $unsupportedIds = [];
        $attributionMissing = 0;
        $blockedHits = 0;

        foreach ((array) ($draft['sentence_fact_map'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sentenceText = trim((string) ($entry['sentence_text'] ?? ''));
            if ($sentenceText === '') {
                continue;
            }

            $type = (string) ($entry['sentence_type'] ?? 'fact');
            $ids = $this->stringList((array) ($entry['approved_fact_ids'] ?? []));
            $inferenceId = trim((string) ($entry['approved_inference_id'] ?? ''));
            $claimStatus = (string) ($entry['claim_status'] ?? '');
            $needsSupport = in_array($type, ['fact', 'attributed_claim', 'denial', 'context', 'inference'], true);
            $unsupportedForEntry = $this->unsupportedDraftFacts($ids, $approvedFacts);
            $hasSupport = ! $needsSupport || (($ids !== [] || $inferenceId !== '') && $unsupportedForEntry === []);

            foreach ($unsupportedForEntry as $unsupportedId) {
                $unsupportedIds[] = $unsupportedId;
            }

            if ((bool) ($entry['blocked_fact_hit'] ?? false)) {
                $blockedHits++;
            }

            if (($type === 'attributed_claim' || $type === 'denial' || in_array($claimStatus, ['official_claim', 'denial', 'unverified_detail'], true))
                && ! (bool) ($entry['attribution_in_sentence'] ?? false)) {
                $attributionMissing++;
            }

            $entries[] = [
                'normalized' => $this->normalizedTextForReview($sentenceText),
                'needs_support' => $needsSupport,
                'has_support' => $hasSupport,
            ];
        }

        $unmapped = 0;
        foreach ($publicBodySentences as $sentence) {
            $bestEntry = null;
            $bestScore = 0.0;
            foreach ($entries as $entry) {
                $score = $this->tokenSimilarity($sentence['normalized'], $entry['normalized']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestEntry = $entry;
                }
            }

            if ($bestEntry === null || $bestScore < 0.82 || ($bestEntry['needs_support'] && ! $bestEntry['has_support'])) {
                $unmapped++;
            }
        }

        return [
            'sentence_fact_map_count' => count($entries),
            'unmapped_public_sentence_count' => $unmapped,
            'unsupported_sentence_fact_id_count' => count(array_values(array_unique($unsupportedIds))),
            'attribution_missing_count' => $attributionMissing,
            'blocked_fact_hit_count' => $blockedHits,
        ];
    }

    /**
     * @param list<array{raw: string, normalized: string}> $publicBodySentences
     * @return array{count: int, hashes: list<string>}
     */
    private function blockedFactsInPublicBody(array $factSheet, string $draftNormalized, array $publicBodySentences): array
    {
        $blockedFacts = [
            ...$this->stringList((array) ($factSheet['facts_low'] ?? [])),
            ...$this->stringList((array) ($factSheet['manual_review_items'] ?? [])),
            ...$this->stringList((array) ($factSheet['draft_excluded_facts'] ?? [])),
        ];
        $hashes = [];

        foreach ($blockedFacts as $blockedFact) {
            $blockedNormalized = $this->normalizedTextForReview($blockedFact);
            if (mb_strlen($blockedNormalized) < 20) {
                continue;
            }

            if (str_contains($draftNormalized, $blockedNormalized)) {
                $hashes[] = hash('sha256', $blockedFact);
                continue;
            }

            foreach ($publicBodySentences as $sentence) {
                if ($this->tokenSimilarity($blockedNormalized, $sentence['normalized']) >= 0.86) {
                    $hashes[] = hash('sha256', $blockedFact);
                    break;
                }
            }
        }

        $hashes = array_values(array_unique($hashes));

        return [
            'count' => count($hashes),
            'hashes' => $hashes,
        ];
    }

    /**
     * @param array<int, mixed> $manualReviewItems
     * @param list<array{raw: string, normalized: string}> $draftSentences
     */
    private function manualReviewItemsInDraft(array $manualReviewItems, string $draftNormalized, array $draftSentences): int
    {
        $hits = 0;
        foreach ($manualReviewItems as $item) {
            $itemNormalized = $this->normalizedTextForReview((string) $item);
            if (mb_strlen($itemNormalized) < 20) {
                continue;
            }

            if (str_contains($draftNormalized, $itemNormalized)) {
                $hits++;
                continue;
            }

            foreach ($draftSentences as $draftSentence) {
                if ($this->tokenSimilarity($itemNormalized, $draftSentence['normalized']) >= 0.9) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<NewsSourceDocument> $documents
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function publishedArticlePayload(array $draft, array $documents, array $review): array
    {
        $bodyParts = [
            $draft['intro'] ?? '',
            ...array_map(static fn (array $section): string => trim(implode("\n\n", [
                $section['heading'] ?? '',
                $section['body'] ?? '',
            ])), (array) ($draft['sections'] ?? [])),
        ];

        return [
            'headline' => (string) ($draft['headline'] ?? ''),
            'body' => trim(implode("\n\n", array_filter($bodyParts, static fn (string $part): bool => trim($part) !== ''))),
            'references' => array_map(static fn (NewsSourceDocument $document): array => $document->toReference(), $documents),
            'compliance_log' => [
                'fact_only_drafting' => true,
                'legal_review_passed' => (bool) ($review['passed'] ?? false),
                'image_rights_passed' => (bool) ($review['image_rights_passed'] ?? false),
            ],
        ];
    }

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function failedResult(string $status, array $errors, array $context = []): array
    {
        return [
            'status' => $status,
            'claim_status' => 'pipeline_failed_no_publish',
            'errors' => $errors,
            'published_article' => null,
            'compliance_log' => [
                'fact_only_drafting' => false,
                'legal_review_passed' => false,
                'image_rights_passed' => false,
                'source_link_required' => true,
            ],
            ...$context,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function stableJson(array $value): string
    {
        $this->sortRecursive($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $value
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
