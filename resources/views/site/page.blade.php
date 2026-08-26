@extends('layouts.site')
@section('title', ($page->meta_title ?: $page->title).' — '.config('site.name_bn'))
@section('description', $page->meta_description ?? '')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-8 lg:py-12">
        <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $page->title }}</h1>
        <div class="article-body prose-editorial mt-6 max-w-none text-body">
            {!! $page->body !!}
        </div>
    </div>
@endsection
