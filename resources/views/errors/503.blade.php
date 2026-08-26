{{-- Also what `php artisan down --render="errors::503"` bakes into a static
     file, so nothing here may depend on the application being bootable. --}}
@extends('errors.standalone')

@section('code', '৫০৩')
@section('heading', 'সাময়িকভাবে বন্ধ')
@section('message', 'রক্ষণাবেক্ষণের কাজ চলছে। খুব শিগগিরই আমরা ফিরে আসছি।')
@section('note', 'অনুগ্রহ করে কিছুক্ষণ পর পাতাটি নতুন করে খুলুন।')
