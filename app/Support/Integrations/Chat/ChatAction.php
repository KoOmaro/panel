<?php

namespace App\Support\Integrations\Chat;

/** A button on an alert in a chat. Its value goes in the button's data, with the message's id. */
enum ChatAction: string
{
    case CheckNow = 'check';
    case Restart = 'restart';
    case Mute = 'mute';
}
