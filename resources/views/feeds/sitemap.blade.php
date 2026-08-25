<?= '<?xml version="1.0" encoding="UTF-8"?>'."\n" ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>{{ route('home') }}</loc>
    <changefreq>hourly</changefreq>
    <priority>1.0</priority>
  </url>
@foreach ($categories as $category)
  <url>
    <loc>{{ route('category.show', $category->path) }}</loc>
    <lastmod>{{ $category->updated_at?->toAtomString() }}</lastmod>
    <changefreq>hourly</changefreq>
    <priority>0.8</priority>
  </url>
@endforeach
@foreach ($articles as $article)
  <url>
    <loc>{{ $article->url }}</loc>
    <lastmod>{{ $article->updated_at?->toAtomString() }}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.6</priority>
  </url>
@endforeach
</urlset>
