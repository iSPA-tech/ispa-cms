<?php

namespace Cms\Core\Contracts\Account;

use App\Models\User;

/**
 * Resolves the social login providers linked to an account.
 *
 * Lives in Core so the Account module can depend on this abstraction without
 * coupling to the SocialAuth module (or its storage). The SocialAuth module
 * binds an implementation when it is enabled; when it is not, the Account
 * module resolves nothing and treats every account as having no linked
 * providers.
 */
interface SocialIdentityLinkResolver
{
    /**
     * Lower-cased, de-duplicated provider keys linked to the given user.
     *
     * @return list<string>
     */
    public function linkedProviders(User $user): array;
}
