<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A client email built from a template, with an optional invoice PDF. */
class LoggedEmail extends Mailable
{
    /** @param  array{data: string, name: string}|null  $pdf */
    public function __construct(
        private string $emailSubject,
        private string $bodyMarkdown,
        private ?string $replyToAddress = null,
        private ?array $pdf = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            text: 'emails.text',
            with: ['html' => EmailTemplates::html($this->bodyMarkdown), 'text' => $this->bodyMarkdown],
        );
    }

    public function attachments(): array
    {
        return $this->pdf
            ? [Attachment::fromData(fn () => $this->pdf['data'], $this->pdf['name'])->withMime('application/pdf')]
            : [];
    }
}
