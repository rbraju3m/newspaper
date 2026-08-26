<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * One exception, mailed to whoever is on call.
 *
 * A Mailable rather than `Mail::raw()` for a reason that only shows up in the
 * test suite: `MailFake::raw()` is an empty method, so a raw send records
 * nothing and cannot be asserted on. An alerting path nobody can test is an
 * alerting path nobody should trust.
 *
 * Plain text, and English. Every reader-facing string in this application is
 * Bangla; this one is read by whoever is on call, beside a stack trace that is
 * English whatever we do.
 */
class ErrorAlert extends Mailable
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $summary,
        public string $trace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('site.name_en', config('app.name')).'] '
                .class_basename($this->type).': '.Str::limit(strtok($this->summary, "\n") ?: $this->type, 80),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.error-alert');
    }
}
