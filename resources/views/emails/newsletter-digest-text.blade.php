{{ config('site.name_bn') }} — {{ $frequency === 'weekly' ? 'সপ্তাহের খবর' : 'আজকের খবর' }}
@bnfulldate()
@foreach ($articles as $article)

{{ $loop->iteration }}. {{ $article->title }}
@if ($article->category)   [{{ $article->category->name }}]@endif

   {{ $article->url }}
@endforeach

--
আপনি {{ config('site.name_bn') }}-এর {{ $frequency === 'weekly' ? 'সাপ্তাহিক' : 'দৈনিক' }} নিউজলেটার পাচ্ছেন।
নিউজলেটার বন্ধ করতে: {{ $unsubscribeUrl }}
পছন্দ পরিবর্তন করতে: {{ route('account.preferences') }}
