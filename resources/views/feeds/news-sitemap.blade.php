<?= '<?xml version="1.0" encoding="UTF-8"?>'."\n" ?>
{{-- Google News only accepts articles from the last 48 hours; the controller
     already restricts the window. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
@foreach ($articles as $article)
  <url>
    <loc>{{ $article->url }}</loc>
    <news:news>
      <news:publication>
        <news:name>{{ config('site.name_bn') }}</news:name>
        <news:language>{{ $article->locale }}</news:language>
      </news:publication>
      <news:publication_date>{{ $article->published_at?->toAtomString() }}</news:publication_date>
      <news:title>{{ $article->title }}</news:title>
    </news:news>
  </url>
@endforeach
</urlset>
