{{ \App\Support\Locale::siteName() }} — {{ $frequency === 'weekly' ? __('সপ্তাহের খবর') : __('আজকের খবর') }}
@bnfulldate()
@foreach ($articles as $article)

{{ $loop->iteration }}. {{ $article->title }}
@if ($article->category)   [{{ $article->category->name }}]@endif

   {{ $article->url }}
@endforeach

--
{{ __(':paper-এর :frequency নিউজলেটার পাচ্ছেন।', ['paper' => \App\Support\Locale::siteName(), 'frequency' => $frequency === 'weekly' ? __('সাপ্তাহিক') : __('দৈনিক')]) }}
{{ __('নিউজলেটার বন্ধ করতে:') }} {{ $unsubscribeUrl }}
{{ __('পছন্দ পরিবর্তন করতে:') }} {{ route('account.preferences') }}
