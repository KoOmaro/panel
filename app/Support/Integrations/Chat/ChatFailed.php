<?php

namespace App\Support\Integrations\Chat;

use RuntimeException;

/** A chat that didn't take a message, in words a person can act on. Never carries a token. */
final class ChatFailed extends RuntimeException {}
