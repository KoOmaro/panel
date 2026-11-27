<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * A chat account that posts alerts and deploys to channels (Slack, Teams):
 * the account page picks what goes where and can send a test.
 */
interface PostsToChannels
{
    /** @return array<string, list<mixed>> */
    public function settingsRules(): array;

    /**
     * The choices to keep in the account's settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function settings(Integration $integration, array $data): array;

    /**
     * Posts a test message and says where it went.
     *
     * @throws IntegrationFailed
     */
    public function sendTest(Integration $integration): string;
}
