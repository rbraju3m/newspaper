@php
    // ThrottleRequests puts the wait on the response, so tell the reader how
    // long rather than leaving them to guess and keep retrying.
    $retryAfter = (int) (isset($exception) ? ($exception->getHeaders()['Retry-After'] ?? 0) : 0);
@endphp

@extends('errors.layout')

@section('code', \App\Support\Fmt::digits(429))
@section('heading', __('একটু ধীরে'))
@section('message')
    {{ __('অল্প সময়ে অনেকবার অনুরোধ এসেছে, তাই কিছুক্ষণের জন্য থামানো হয়েছে।') }}
    @if ($retryAfter > 0)
        <span class="whitespace-nowrap">{{ __(':seconds সেকেন্ড পর আবার চেষ্টা করুন।', ['seconds' => \App\Support\Fmt::digits($retryAfter)]) }}</span>
    @else
        {{ __('একটু পরে আবার চেষ্টা করুন।') }}
    @endif
@endsection
