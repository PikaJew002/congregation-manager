<?php

namespace App\Mail\MailChannels;

use RuntimeException;

/** The congregation has not finished setting up email sending. */
class EmailSendingNotReady extends RuntimeException {}
