<?php

namespace App\Mail\MailChannels;

/** MailChannels throttled the account. Slow down, then try again. */
class SendingTooFast extends TemporaryFailure {}
