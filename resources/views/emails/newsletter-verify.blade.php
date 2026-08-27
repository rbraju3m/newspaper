<x-mail.shell tagline="{{ config('site.tagline') }}">
    <p style="margin:0 0 14px;font-size:19px;font-weight:700;color:#14171A;
              font-family:'Noto Serif Bengali',Georgia,serif;">
        @if ($name)
            {{ $name }}, একটি ধাপ বাকি
        @else
            আর একটি ধাপ বাকি
        @endif
    </p>

    <p style="margin:0 0 20px;">
        {{ config('site.name_bn') }} নিউজলেটার পেতে নিচের বোতামে ক্লিক করে আপনার
        ইমেইল ঠিকানাটি নিশ্চিত করুন।
    </p>

    {{-- Bulletproof-ish button: a table cell with a background, because Outlook
         ignores padding on an anchor and would render a bare blue link. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr>
            <td style="background-color:#C8102E;border-radius:8px;">
                <a href="{{ $verifyUrl }}"
                   style="display:inline-block;padding:13px 30px;color:#FFFFFF;font-size:15px;
                          font-weight:700;text-decoration:none;
                          font-family:'Noto Sans Bengali',Arial,sans-serif;">সাবস্ক্রিপশন নিশ্চিত করুন</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 8px;font-size:13px;color:#616874;">
        বোতামটি কাজ না করলে এই ঠিকানাটি ব্রাউজারে কপি করুন:
    </p>
    {{-- Latin run, so Inter — and word-break, because a 64-character token
         overflows a 600px table on a phone otherwise. --}}
    <p style="margin:0;font-size:12px;word-break:break-all;
              font-family:Inter,Arial,sans-serif;color:#1A5FB4;">{{ $verifyUrl }}</p>

    <x-slot:footer>
        আপনি এই ইমেইলটি পেয়েছেন কারণ {{ config('site.name_bn') }}-এ এই ঠিকানাটি দিয়ে
        নিউজলেটারের অনুরোধ করা হয়েছিল। আপনি না করে থাকলে কিছুই করতে হবে না — নিশ্চিত
        না করা পর্যন্ত আমরা কোনো নিউজলেটার পাঠাব না।
    </x-slot:footer>
</x-mail.shell>
