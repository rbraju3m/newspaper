@props(['tagline' => null, 'footer' => null])

{{--
    The frame every newsletter mail sits in.

    Table layout and inline styles, because an inbox is not a browser: Gmail
    strips <style> from the head on its mobile clients, Outlook renders through
    Word, and neither knows what a CSS custom property is — so none of the
    semantic tokens in app.css can be used here. Every colour is literal, and
    that is correct rather than sloppy. The values are the light-theme tokens
    by hand; an email has no theme to switch.

    The masthead is type on a coloured bar rather than a logo image: a remote
    image is blocked by default in most clients, and a masthead that renders as
    a broken-image icon is worse than one made of words.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:0;padding:0;background-color:#F4F5F7;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background-color:#FFFFFF;border-radius:12px;
                          border:1px solid #E5E7EB;">

                <tr>
                    <td style="background-color:#C8102E;padding:20px 28px;border-radius:12px 12px 0 0;">
                        <a href="{{ url('/') }}"
                           style="color:#FFFFFF;font-size:22px;font-weight:700;text-decoration:none;
                                  font-family:'Noto Serif Bengali',Georgia,serif;">{{ config('site.name_bn') }}</a>
                        @if ($tagline)
                            <div style="color:#F6C3CB;font-size:12px;margin-top:5px;
                                        font-family:'Noto Sans Bengali',Arial,sans-serif;">{{ $tagline }}</div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;font-family:'Noto Sans Bengali',Arial,sans-serif;
                               font-size:15px;line-height:1.75;color:#3A4046;">
                        {{ $slot }}
                    </td>
                </tr>

                @if ($footer)
                    <tr>
                        <td style="background-color:#F9FAFB;border-top:1px solid #E5E7EB;padding:20px 28px;
                                   border-radius:0 0 12px 12px;
                                   font-family:'Noto Sans Bengali',Arial,sans-serif;font-size:12px;
                                   line-height:1.8;color:#616874;">{{ $footer }}</td>
                    </tr>
                @endif
            </table>

            <div style="font-family:'Noto Sans Bengali',Arial,sans-serif;font-size:11px;
                        color:#8A9099;text-align:center;padding-top:14px;">
                © @bn(now()->year) {{ config('site.name_bn') }}
            </div>
        </td>
    </tr>
</table>
