@php
    // ThrottleRequests puts the wait on the response, so tell the reader how
    // long rather than leaving them to guess and keep retrying.
    $retryAfter = (int) (isset($exception) ? ($exception->getHeaders()['Retry-After'] ?? 0) : 0);
@endphp

@extends('errors.layout')

@section('code', '৪২৯')
@section('heading', 'একটু ধীরে')
@section('message')
    অল্প সময়ে অনেকবার অনুরোধ এসেছে, তাই কিছুক্ষণের জন্য থামানো হয়েছে।
    @if ($retryAfter > 0)
        <span class="whitespace-nowrap">@bn($retryAfter) সেকেন্ড পর আবার চেষ্টা করুন।</span>
    @else
        একটু পরে আবার চেষ্টা করুন।
    @endif
@endsection
