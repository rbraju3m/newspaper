@foreach ($articles as $article)
    <x-article.card :article="$article" variant="list"
                    class="border-b border-line pb-4 last:border-0" />
@endforeach
