<?php

namespace App\Mail\Transport;

use App\Mail\MailChannels\MailChannelsApi;
use App\Mail\MailChannels\MessageRejected;
use App\Mail\MailChannels\RecipientSuppressed;
use App\Mail\MailChannels\TemporaryFailure;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends through the MailChannels Email API, one recipient per message.
 *
 * Used both for the platform's own mail (key and DKIM private key from .env)
 * and for each congregation's mail (its own key, signed by MailChannels with
 * the DKIM key it holds for the congregation's domain, so only the selector is
 * sent).
 */
class MailChannelsTransport extends AbstractTransport
{
    /** Header a message can set to name its campaign. Stripped before sending. */
    public const CAMPAIGN_HEADER = 'X-MailChannels-Campaign';

    /** How the send's request id, which delivery events carry, is passed back in the sent message's debug log. */
    public const REQUEST_ID_PREFIX = 'MailChannels request_id: ';

    /** The request id MailChannels gave a sent message, if any. */
    public static function requestId(string $debug): ?string
    {
        return preg_match('/^'.preg_quote(self::REQUEST_ID_PREFIX, '/').'(\S+)$/m', $debug, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array{domain?: string|null, selector?: string|null, private_key?: string|null}  $dkim
     * @param  array{transactional?: bool|null, tracking?: bool|null}  $options  Null leaves MailChannels' default.
     */
    public function __construct(
        private string $apiKey,
        private array $dkim = [],
        private array $options = [],
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $recipients = $email->getTo();

        if (count($recipients) !== 1) {
            throw new TransportException('MailChannels sends one recipient per message.');
        }

        $recipient = $recipients[0];
        $from = $email->getFrom()[0] ?? null;

        if (! $from instanceof Address) {
            throw new TransportException('MailChannels requires a from address.');
        }

        $personalization = [
            'to' => [[
                'email' => $recipient->getAddress(),
                'name' => $recipient->getName(),
            ]],
            ...$this->dkimFields(),
        ];

        $content = [];

        if ($email->getTextBody() !== null) {
            $content[] = ['type' => 'text/plain', 'value' => $email->getTextBody()];
        }

        if ($email->getHtmlBody() !== null) {
            $content[] = ['type' => 'text/html', 'value' => $email->getHtmlBody()];
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => [
                'email' => $from->getAddress(),
                'name' => $from->getName(),
            ],
            'subject' => $email->getSubject() ?? '',
            'content' => $content,
        ];

        $headers = $this->customHeaders($email);

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $replyTo = $email->getReplyTo()[0] ?? null;

        if ($replyTo instanceof Address) {
            $payload['reply_to'] = [
                'email' => $replyTo->getAddress(),
                'name' => $replyTo->getName(),
            ];
        }

        $campaign = $email->getHeaders()->get(self::CAMPAIGN_HEADER)?->getBodyAsString();

        if ($campaign) {
            $payload['campaign_id'] = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $campaign), 0, 48);
        }

        if (($this->options['transactional'] ?? null) !== null) {
            $payload['transactional'] = (bool) $this->options['transactional'];
        }

        if (($this->options['tracking'] ?? null) !== null) {
            $payload['tracking_settings'] = [
                'click_tracking' => ['enable' => (bool) $this->options['tracking']],
                'open_tracking' => ['enable' => (bool) $this->options['tracking']],
            ];
        }

        try {
            $response = MailChannelsApi::request($this->apiKey)->post(MailChannelsApi::BASE_URL.'/send', $payload);
        } catch (ConnectionException $exception) {
            throw new TemporaryFailure('Could not reach MailChannels: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw MailChannelsApi::failure($response);
        }

        $result = $response->json('results.0');

        if (is_array($result) && ($result['status'] ?? null) === 'failed') {
            $reason = (string) ($result['reason'] ?? 'no reason given');

            throw MailChannelsApi::isSuppression($reason)
                ? new RecipientSuppressed('MailChannels will not send to this address: '.$reason)
                : new MessageRejected('MailChannels rejected the message: '.$reason);
        }

        $requestId = $response->json('request_id');

        if (filled($requestId)) {
            $message->appendDebug(self::REQUEST_ID_PREFIX.$requestId."\n");
        }

        if (is_array($result) && filled($result['message_id'] ?? null)) {
            $message->setMessageId((string) $result['message_id']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function dkimFields(): array
    {
        $domain = $this->dkim['domain'] ?? null;
        $selector = $this->dkim['selector'] ?? null;

        if (! $domain || ! $selector) {
            return [];
        }

        $fields = ['dkim_domain' => $domain, 'dkim_selector' => $selector];

        if ($this->dkim['private_key'] ?? null) {
            $fields['dkim_private_key'] = $this->dkim['private_key'];
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $headers = [];

        foreach (['List-Unsubscribe', 'List-Unsubscribe-Post'] as $name) {
            $header = $email->getHeaders()->get($name);

            if ($header) {
                $headers[$name] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    public function __toString(): string
    {
        return 'mailchannels';
    }
}
