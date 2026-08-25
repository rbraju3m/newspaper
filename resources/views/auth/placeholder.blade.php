@extends('layouts.site')
@section('title', 'শীঘ্রই আসছে — '.config('site.name_bn'))
@push('head')<meta name="robots" content="noindex">@endpush

@section('content')
    <div class="mx-auto max-w-md px-4 py-20">
        <x-ui.empty-state icon="user" title="এই সুবিধাটি শীঘ্রই আসছে"
                          message="পাঠক অ্যাকাউন্ট, বুকমার্ক ও মন্তব্য পরবর্তী ধাপে যুক্ত হচ্ছে।">
            <a href="{{ route('home') }}"
               class="mt-4 rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                প্রচ্ছদে ফিরুন
            </a>
        </x-ui.empty-state>
    </div>
@endsection
