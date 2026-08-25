<?= '<?xml version="1.0" encoding="UTF-8"?>'."\n" ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>{{ config('site.name_bn') }}</title>
    <link>{{ route('home') }}</link>
    <description>{{ config('site.description') }}</description>
    <language>bn-BD</language>
    <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
    <atom:link href="{{ route('feed.rss') }}" rel="self" type="application/rss+xml"/>
@foreach ($articles as $article)
    <item>
      <title>{{ $article->title }}</title>
      <link>{{ $article->url }}</link>
      <guid isPermaLink="true">{{ $article->url }}</guid>
      <pubDate>{{ $article->published_at?->toRfc2822String() }}</pubDate>
      @if ($article->category)<category>{{ $article->category->name }}</category>@endif
      @if ($article->author)<dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/">{{ $article->author->name }}</dc:creator>@endif
      <description>{{ $article->excerpt }}</description>
      @if ($article->image_url)<enclosure url="{{ $article->image_url }}" type="image/jpeg"/>@endif
    </item>
@endforeach
  </channel>
</rss>
