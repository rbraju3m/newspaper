@php
    // The lead story gets the picture and the excerpt; the rest are a list.
    // An email that gives eight stories equal weight is eight stories nobody
    // reads — the shape has to say which one matters.
    $lead = $articles->first();
    $rest = $articles->skip(1);
@endphp

<x-mail.shell :tagline="\App\Support\Bangla::fullDate(now())">
    <p style="margin:0 0 20px;font-size:14px;color:#616874;">
        {{ $name ? $name.', ' : '' }}{{ $frequency === 'weekly'
            ? 'গত সাত দিনের বাছাই করা খবর।'
            : 'আজ সকালে যা জানা দরকার।' }}
    </p>

    {{-- ── Lead ──────────────────────────────────────────────────────── --}}
    @if ($lead)
        @if ($lead->image_url)
            {{-- One image, and only on the lead. Most clients block remote
                 images until the reader allows them, so nothing may depend on
                 it rendering — the headline below carries the story alone. --}}
            <a href="{{ $lead->url }}" style="text-decoration:none;">
                <img src="{{ $lead->image_url }}" width="544" alt=""
                     style="display:block;width:100%;max-width:544px;height:auto;
                            border-radius:8px;border:0;outline:none;margin:0 0 14px;">
            </a>
        @endif

        @if ($lead->category)
            <div style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.02em;
                        color:{{ $lead->category->color ?: '#C8102E' }};">{{ $lead->category->name }}</div>
        @endif

        <a href="{{ $lead->url }}"
           style="display:block;margin:0 0 10px;font-size:23px;line-height:1.4;font-weight:700;
                  color:#14171A;text-decoration:none;
                  font-family:'Noto Serif Bengali',Georgia,serif;">{{ $lead->title }}</a>

        @if ($lead->excerpt)
            <p style="margin:0 0 8px;font-size:15px;line-height:1.75;color:#3A4046;">
                {{ Str::limit($lead->excerpt, 180) }}
            </p>
        @endif

        <p style="margin:0 0 26px;font-size:12px;color:#8A9099;">
            @bnago($lead->published_at)
            @if ($lead->reading_time) · @bn($lead->reading_time) মিনিট পড়া @endif
        </p>
    @endif

    {{-- ── The rest ──────────────────────────────────────────────────── --}}
    @if ($rest->isNotEmpty())
        <div style="border-top:2px solid #14171A;padding-top:14px;margin-bottom:6px;
                    font-size:13px;font-weight:700;color:#14171A;
                    font-family:'Noto Serif Bengali',Georgia,serif;">আরও খবর</div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            @foreach ($rest as $article)
                <tr>
                    <td style="padding:14px 0;border-bottom:1px solid #E5E7EB;">
                        @if ($article->category)
                            <div style="margin:0 0 4px;font-size:11px;font-weight:700;
                                        color:{{ $article->category->color ?: '#C8102E' }};">{{ $article->category->name }}</div>
                        @endif
                        <a href="{{ $article->url }}"
                           style="font-size:16px;line-height:1.5;font-weight:600;color:#14171A;
                                  text-decoration:none;
                                  font-family:'Noto Serif Bengali',Georgia,serif;">{{ $article->title }}</a>
                        <div style="margin-top:5px;font-size:11px;color:#8A9099;">@bnago($article->published_at)</div>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;">
        <tr>
            <td style="border:1px solid #C8102E;border-radius:8px;">
                <a href="{{ url('/') }}"
                   style="display:inline-block;padding:11px 26px;color:#C8102E;font-size:14px;
                          font-weight:700;text-decoration:none;
                          font-family:'Noto Sans Bengali',Arial,sans-serif;">সব খবর দেখুন</a>
            </td>
        </tr>
    </table>

    <x-slot:footer>
        আপনি {{ config('site.name_bn') }}-এর
        {{ $frequency === 'weekly' ? 'সাপ্তাহিক' : 'দৈনিক' }} নিউজলেটার পাচ্ছেন।<br>
        {{-- Plainly worded and plainly visible. A reader who cannot find this
             presses "spam" instead, and that is the one signal there is no
             recovering from. --}}
        <a href="{{ $unsubscribeUrl }}" style="color:#616874;text-decoration:underline;">নিউজলেটার বন্ধ করুন</a>
        &nbsp;·&nbsp;
        <a href="{{ route('account.preferences') }}" style="color:#616874;text-decoration:underline;">পছন্দ পরিবর্তন করুন</a>
    </x-slot:footer>
</x-mail.shell>
