<?php

namespace App\Support\Integrations;

use Illuminate\Http\Request;

/**
 * A provider connected by signing in there (OAuth) instead of pasting a
 * token. Its connectPath() is integrations/oauth/{provider}, which sends the
 * person to authorizeUrl(); the provider sends them back to
 * integrations/oauth/{provider}/callback with a code. Provider::available()
 * says whether this install has the app the sign-in goes through.
 */
interface ConnectsWithOAuth
{
    public function authorizeUrl(string $state): string;

    /**
     * Swaps the provider's answer for the account, its token in `credentials`.
     *
     * @throws IntegrationFailed
     */
    public function exchange(Request $request): ConnectedAccount;

    /** What tells one account there from another (Slack's team id), so it's connected once and connecting again updates it. */
    public function accountId(ConnectedAccount $account): string;
}
