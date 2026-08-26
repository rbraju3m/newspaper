@php
    // 502 and 504 reach here when the application is the thing answering.
    $code = \App\Support\Bangla::digits(isset($exception) ? $exception->getStatusCode() : 500);
@endphp

@extends('errors.standalone')

@section('code', $code)
@section('heading', 'সেবাটি এখন পাওয়া যাচ্ছে না')
@section('message', 'সাময়িক কারিগরি সমস্যার কারণে পাতাটি দেখানো যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।')
