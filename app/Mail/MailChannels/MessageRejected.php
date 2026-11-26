<?php

namespace App\Mail\MailChannels;

/** This message is bad (an address, its size). Other messages can still go. */
class MessageRejected extends MailChannelsException {}
