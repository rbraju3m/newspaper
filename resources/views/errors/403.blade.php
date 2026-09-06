@extends('errors.layout')

@section('code', \App\Support\Fmt::digits(403))
@section('heading', __('এই পাতায় প্রবেশের অনুমতি নেই'))
@section('message', __('আপনার অ্যাকাউন্টে এই পাতাটি দেখার অনুমতি নেই। ভুল মনে হলে সম্পাদকের সঙ্গে যোগাযোগ করুন।'))
