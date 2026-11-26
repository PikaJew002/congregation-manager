<?php

namespace App\Mail\MailChannels;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * MailChannels did not take a message. The subclass says whose problem it is.
 */
abstract class MailChannelsException extends TransportException {}
