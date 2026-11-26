<?php

namespace App\Mail\MailChannels;

/** MailChannels is down, slow, or asked us to slow down. Trying later may work. */
class TemporaryFailure extends MailChannelsException {}
