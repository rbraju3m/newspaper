{{-- Grid page of cards. Rendered both on first paint and as the JSON fragment
     infinite scroll appends, so the markup can never drift between the two. --}}
@foreach ($articles as $article)
    <x-article.card :article="$article" variant="standard" />
@endforeach
