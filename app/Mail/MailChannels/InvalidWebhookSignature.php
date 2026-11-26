<?php

namespace App\Mail\MailChannels;

use RuntimeException;

/** A webhook request that did not come from MailChannels, or was replayed. */
class InvalidWebhookSignature extends RuntimeException {}
