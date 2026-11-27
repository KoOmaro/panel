<?php

namespace App\Support\Integrations\Chat;

/**
 * A chat that alerts and deploy notices go to (Telegram, Discord, Slack,
 * Teams). ChatTargets hands every alert to every registered target; each
 * decides whether the workspace set it up and wants this alert, posts it,
 * and keeps whatever it needs to edit it when the alert that says it's
 * fixed arrives (ChatAlert::thread()). Failures are logged, never thrown.
 */
interface ChatTarget
{
    public function deliver(ChatAlert $alert): void;
}
