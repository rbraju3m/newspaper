@php
    // Anything in the 4xx range without a page of its own — 400, 405, 413 and
    // friends. Laravel falls back here before it falls back to its own page.
    $code = \App\Support\Bangla::digits(isset($exception) ? $exception->getStatusCode() : 400);
@endphp

@extends('errors.layout')

@section('code', $code)
@section('heading', 'অনুরোধটি সম্পন্ন করা যায়নি')
@section('message', 'অনুরোধটিতে কোনো সমস্যা ছিল বলে পাতাটি দেখানো যায়নি। ঠিকানাটি যাচাই করে আবার চেষ্টা করুন।')
