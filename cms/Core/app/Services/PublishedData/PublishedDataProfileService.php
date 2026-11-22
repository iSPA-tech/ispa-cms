<?php

namespace Cms\Core\Services\PublishedData;

final class PublishedDataProfileService
{
    public const NEWS_ARTICLE_EDITORIAL_PROFILE = 'news_article_editorial';
    public const ENTITY_DIRECTORY_VERIFIED_PROFILE = 'entity_directory_verified';

    /**
     * @return array<string, PublishedDataProfile>
     */
    public function allProfiles(): array
    {
        return [
            self::NEWS_ARTICLE_EDITORIAL_PROFILE => new PublishedDataProfile(
                slug: self::NEWS_ARTICLE_EDITORIAL_PROFILE,
                name: 'News Article Editorial',
                duplicateThreshold: 0.94,
                relatedClusterThreshold: 0.82,
                strictVerificationRequired: false,
                allowPublishedFirst: true,
                requiresContentQualityCheck: true,
                requiresCopyrightQualityCheck: true,
                requiresSourceQualityCheck: true,
                qualityChecks: ['content', 'copyright', 'source'],
                requiredEvidence: ['source_url_or_canonical_url', 'source_attribution', 'content_fingerprint_or_embedding'],
                candidateTypes: ['article', 'news', 'post', 'content'],
            ),
            self::ENTITY_DIRECTORY_VERIFIED_PROFILE => new PublishedDataProfile(
                slug: self::ENTITY_DIRECTORY_VERIFIED_PROFILE,
                name: 'Entity Directory Verified',
                duplicateThreshold: 0.94,
                relatedClusterThreshold: 0.82,
                strictVerificationRequired: true,
                allowPublishedFirst: false,
                requiresContentQualityCheck: true,
                requiresCopyrightQualityCheck: false,
                requiresSourceQualityCheck: true,
                qualityChecks: ['source_identity', 'field_evidence', 'contact_or_geo_verification', 'trusted_publish_gate'],
                requiredEvidence: ['external_identity_or_canonical_key', 'field_source_evidence', 'verified_contact_or_coordinate'],
                candidateTypes: ['authority', 'place', 'vehicle_business', 'showroom', 'medical_facility', 'rescue_contact'],
            ),
        ];
    }

    public function getProfile(string $profileSlug): PublishedDataProfile
    {
        $profiles = $this->allProfiles();
        if (! array_key_exists($profileSlug, $profiles)) {
            throw new \InvalidArgumentException("Unknown published-data profile [{$profileSlug}].");
        }

        return $profiles[$profileSlug];
    }

    public function profileForCandidateType(string $candidateType): PublishedDataProfile
    {
        $candidateType = trim(strtolower($candidateType));

        foreach ($this->allProfiles() as $profile) {
            if (in_array($candidateType, $profile->candidateTypes, true)) {
                return $profile;
            }
        }

        return $this->getProfile(self::ENTITY_DIRECTORY_VERIFIED_PROFILE);
    }
}
