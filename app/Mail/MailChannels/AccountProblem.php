<?php

namespace App\Mail\MailChannels;

/**
 * The account cannot send right now: a wrong key, a domain it may not send
 * from, or a used-up quota. Nothing else will go through until it is fixed.
 */
class AccountProblem extends MailChannelsException {}
