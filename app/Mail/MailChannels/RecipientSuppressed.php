<?php

namespace App\Mail\MailChannels;

/**
 * MailChannels refused the email because the address is on its suppression
 * list (an earlier hard bounce, complaint or unsubscribe).
 */
class RecipientSuppressed extends MessageRejected {}
