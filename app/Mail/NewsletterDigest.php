<?php

namespace App\Mail;

use App\Models\Article;
use App\Models\NewsletterSubscriber;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;

/**
 * One edition, addressed to one reader.
 *
 * The headers are the part that is easy to leave out and expensive to leave
 * out. `List-Unsubscribe` with `List-Unsubscribe-Post` is RFC 8058 one-click:
 * it puts an *Unsubscribe* control in Gmail's and Outlook's own chrome, and
 * bulk senders that omit it now get throttled or filtered on reputation alone.
 * More to the point, a reader who cannot find the unsubscribe link presses
 * "spam" instead, and that is the one signal there is no recovering from.
 *
 * `List-Unsubscribe` names the POST route rather than the confirmation page —
 * the mail client calls it directly and never renders anything, so a page
 * asking "are you sure?" would leave the reader still subscribed and certain
 * they had unsubscribed.
 *
 * Not queued, for the same reason nothing else here is: no worker runs. The
 * command sends inline and is a cron process, where blocking is free.
 *
 * @param  Collection<int, Article>  $articles
 */
class NewsletterDigest extends Mailable
{
    public function __construct(
        public NewsletterSubscriber $subscriber,
        public Collection $articles,
        public string $frequency,
        public string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.route('newsletter.unsubscribe.click', $this->subscriber->token).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            // Threads a reader's editions together as a series rather than as
            // unrelated mail, and tells a filter this is a list rather than a
            // person writing to them.
            'List-ID' => '<newsletter.'.parse_url((string) config('app.url'), PHP_URL_HOST).'>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter-digest',
            text: 'emails.newsletter-digest-text',
            with: [
                'unsubscribeUrl' => $this->subscriber->unsubscribeUrl(),
                'name' => $this->subscriber->name,
            ],
        );
    }
}
