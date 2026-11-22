<?php

declare(strict_types=1);

namespace Cms\Core\Support\Web;

final class PublicStructuredData
{
    /** @param array<string, mixed> $attributes */
    public static function organization(string $name, string $siteUrl, array $attributes = []): array
    {
        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $name,
            'url' => $siteUrl,
        ];

        foreach (['logo', 'telephone', 'foundingDate'] as $field) {
            if (($value = self::text($attributes[$field] ?? null)) !== '') {
                $organization[$field] = $value;
            }
        }
        if (($address = self::text($attributes['address'] ?? null)) !== '') {
            $organization['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address];
        }
        if (($founder = self::text($attributes['founder'] ?? null)) !== '') {
            $organization['founder'] = ['@type' => 'Person', 'name' => $founder];
        }
        $sameAs = array_values(array_filter(array_map(self::text(...), is_array($attributes['sameAs'] ?? null) ? $attributes['sameAs'] : [])));
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }

        return $organization;
    }

    public static function website(string $name, string $siteUrl, ?array $publisher = null): array
    {
        $website = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $siteUrl,
        ];
        if ($publisher !== null) {
            $website['publisher'] = $publisher;
        }

        return $website;
    }

    /** @param list<string> $organizationAliases */
    public static function author(string $name, string $organizationName, array $organizationAliases = []): array
    {
        $normalizedName = self::normalized($name);
        if ($normalizedName === '') return [];
        $organizationNames = array_map(self::normalized(...), [
            ...$organizationAliases, $organizationName,
            $organizationName.' Team', $organizationName.' Editorial Team',
        ]);
        $isOrganization = in_array($normalizedName, $organizationNames, true)
            || self::isEditorialTeam($normalizedName);

        return ['@type' => $isOrganization ? 'Organization' : 'Person', 'name' => $name];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function normalized(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function isEditorialTeam(string $name): bool
    {
        return preg_match('/^(?:(?:the )?editorial(?: team| board)?|team|ban biên tập|đội ngũ|tòa soạn|toà soạn)(?:$|\s)/u', $name) === 1;
    }
}
