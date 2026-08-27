<?php

namespace App\Mail;

use App\Models\NewsletterSubscriber;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The double opt-in mail — the half of the subscribe flow that was never built.
 *
 * `NewsletterController::store()` has always told the reader to check their
 * inbox, and until now nothing arrived, so a footer subscriber could never
 * reach `verified_at` and `active()` never matched them. Confirming is not a
 * formality: it is the only thing standing between the send list and anyone
 * who fancies typing a stranger's address into a form.
 *
 * Not queued. This deployment runs no queue worker, so a queued mail would sit
 * in the `jobs` table for ever — the same reason `ErrorAlerter` sends inline.
 * The subscribe endpoint is throttled to five an hour, which is what makes
 * paying SMTP latency in the request acceptable.
 */
class NewsletterVerify extends Mailable
{
    public function __construct(public NewsletterSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'সাবস্ক্রিপশন নিশ্চিত করুন — '.config('site.name_bn'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter-verify',
            text: 'emails.newsletter-verify-text',
            with: [
                'verifyUrl' => route('newsletter.verify', $this->subscriber->token),
                'name' => $this->subscriber->name,
            ],
        );
    }
}
