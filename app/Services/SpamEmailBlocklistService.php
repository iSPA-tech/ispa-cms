<?php

namespace App\Services;

use App\Models\SpamEmailBlock;
use App\Models\SpamEmailDomainBlock;
use Cms\Core\Services\Security\PersonalDataIdentityService;

class SpamEmailBlocklistService
{
    private const COMMON_PUBLIC_EMAIL_DOMAINS = [
        'aol.com',
        'gmail.com',
        'gmx.com',
        'googlemail.com',
        'hotmail.com',
        'icloud.com',
        'live.com',
        'mac.com',
        'mail.com',
        'me.com',
        'msn.com',
        'outlook.com',
        'proton.me',
        'protonmail.com',
        'yahoo.com',
        'yandex.com',
    ];

    private const COMMON_PUBLIC_EMAIL_DOMAIN_PREFIXES = [
        'gmail.',
        'googlemail.',
        'hotmail.',
        'outlook.',
        'live.',
        'yahoo.',
    ];

    public function __construct(
        protected PersonalDataIdentityService $identities,
    )
    {
    }

    public function isBlocked(?string $email): bool
    {
        $emailHash = $this->emailHash($email);

        if ($emailHash === null) {
            return false;
        }

        if (SpamEmailBlock::query()->where('email_hash', $emailHash)->exists()) {
            return true;
        }

        $domainHash = $this->customDomainHash($email);

        return $domainHash !== null
            && SpamEmailDomainBlock::query()->where('domain_hash', $domainHash)->exists();
    }

    /**
     * Persist exact sender addresses as one-way hashes.
     *
     * @param array<int, string|null> $emails
     */
    public function block(array $emails, bool $blockDomains = false): bool
    {
        $now = now();
        $emailRecords = [];
        $domainRecords = [];

        foreach ($emails as $email) {
            $emailHash = $this->emailHash($email);

            if ($emailHash === null) {
                return false;
            }

            $emailRecords[$emailHash] = [
                'email_hash' => $emailHash,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $domainHash = $blockDomains ? $this->customDomainHash($email) : null;
            if ($domainHash !== null) {
                $domainRecords[$domainHash] = [
                    'domain_hash' => $domainHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (empty($emailRecords)) {
            return false;
        }

        SpamEmailBlock::query()->upsert(
            array_values($emailRecords),
            ['email_hash'],
            ['updated_at'],
        );

        if (!empty($domainRecords)) {
            SpamEmailDomainBlock::query()->upsert(
                array_values($domainRecords),
                ['domain_hash'],
                ['updated_at'],
            );
        }

        return true;
    }

    protected function emailHash(?string $email): ?string
    {
        $normalized = $this->identities->normalizeEmail($email);

        if ($normalized === null || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $this->identities->contactHash($normalized);
    }

    protected function customDomainHash(?string $email): ?string
    {
        $normalized = $this->identities->normalizeEmail($email);
        if ($normalized === null || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $domain = substr(strrchr($normalized, '@') ?: '', 1);
        if ($domain === '' || $this->isCommonPublicEmailDomain($domain)) {
            return null;
        }

        return $this->identities->contactHash($domain, 'contact-domain');
    }

    protected function isCommonPublicEmailDomain(string $domain): bool
    {
        if (in_array($domain, self::COMMON_PUBLIC_EMAIL_DOMAINS, true)) {
            return true;
        }

        foreach (self::COMMON_PUBLIC_EMAIL_DOMAIN_PREFIXES as $prefix) {
            if (str_starts_with($domain, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
