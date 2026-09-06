<?php

namespace App\Mail;

use App\Models\Article;
use App\Models\NewsletterSubscriber;
use App\Support\Locale;
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
    ) {
        // Pinned to the row rather than left to whatever locale the process
        // happens to be in. `newsletter:send` does switch for the whole of a
        // subscriber's turn — it has to, because the *article selection*
        // happens before this object exists — but a caller that forgets
        // (`--to`, a test, a future admin "send me a preview") would
        // otherwise render an English reader a Bangla mail with correct
        // English links in it, which is the worst of both.
        $this->locale($this->subscriber->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            // The subscriber's own edition, like every other link in the
            // message: the mail client posts this itself, so if it named the
            // Bangla route a reader who signed up in English would be
            // unsubscribed by an endpoint in a language they never chose —
            // which works, but the confirmation they never see would not.
            'List-Unsubscribe' => '<'.Locale::route(
                'newsletter.unsubscribe.click', $this->subscriber->token, $this->subscriber->locale
            ).'>',
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
