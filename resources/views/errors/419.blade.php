@extends('errors.layout')

{{-- 419 is Laravel's expired-CSRF-token code. To a reader it is simply a form
     that sat open too long, so the message says that rather than naming a
     token they have never heard of. --}}
@section('code', '৪১৯')
@section('heading', 'পাতাটির মেয়াদ শেষ হয়ে গেছে')
@section('message', 'ফর্মটি অনেকক্ষণ খোলা ছিল বলে নিরাপত্তার কারণে বাতিল হয়েছে। পাতাটি নতুন করে খুলে আবার চেষ্টা করুন।')

@section('actions')
    <button type="button" onclick="window.history.back()"
            class="rounded-lg border border-line-strong px-5 py-2.5 text-sm font-semibold text-body
                   transition hover:border-brand hover:text-brand">
        আগের পাতায় ফিরুন
    </button>
@endsection
