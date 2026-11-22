@if (is_string($siteName ?? null) && trim($siteName) !== '' && is_string($siteUrl ?? null) && trim($siteUrl) !== '')
    @include('core::components.structured-data', ['data' => [
        \Cms\Core\Support\Web\PublicStructuredData::organization($siteName, $siteUrl, $identityAttributes ?? []),
        \Cms\Core\Support\Web\PublicStructuredData::website($siteName, $siteUrl),
    ]])
@endif
