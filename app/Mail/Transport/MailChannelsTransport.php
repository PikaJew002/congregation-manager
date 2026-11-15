<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class MailChannelsTransport extends AbstractTransport
{
    /**
     * @param  array{domain?: string|null, selector?: string|null, private_key?: string|null}  $dkim
     */
    public function __construct(
        private string $apiKey,
        private array $dkim = [],
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
        ];

        if (($this->dkim['domain'] ?? null) && ($this->dkim['selector'] ?? null) && ($this->dkim['private_key'] ?? null)) {
            $personalization['dkim_domain'] = $this->dkim['domain'];
            $personalization['dkim_selector'] = $this->dkim['selector'];
            $personalization['dkim_private_key'] = $this->dkim['private_key'];
        }

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

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
        ])->acceptJson()->post('https://api.mailchannels.net/tx/v1/send', $payload);

        if ($response->failed()) {
            throw new TransportException('MailChannels rejected the message: '.$response->body());
        }
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
