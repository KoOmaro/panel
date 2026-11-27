<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * A provider with its own part of the account page (n8n's workflows, Grafana's
 * metrics and dashboard). The page loads it as the deferred `account` prop
 * and hands it to the provider's component.
 */
interface HasAccountPage
{
    /** @return array<string, mixed> never the account's credentials */
    public function accountPage(Integration $integration): array;
}
