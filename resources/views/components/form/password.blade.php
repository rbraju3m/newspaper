@props(['name' => 'password', 'label' => 'পাসওয়ার্ড', 'autocomplete' => 'current-password', 'hint' => null])

@php $id = 'f-'.$name; @endphp

{{-- Show/hide toggle: password managers and long Bangla-keyboard passwords make
     blind typing genuinely error-prone. --}}
<div x-data="{ show: false }">
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-semibold text-ink">{{ $label }}</label>

    <div class="relative">
        <input id="{{ $id }}"
               :type="show ? 'text' : 'password'"
               name="{{ $name }}"
               required
               autocomplete="{{ $autocomplete }}"
               @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
               class="w-full rounded-lg border bg-canvas px-3.5 py-2.5 pe-11 text-base text-ink
                      outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20
                      {{ $errors->has($name) ? 'border-brand' : 'border-line-strong' }}">

        <button type="button" @click="show = !show" tabindex="-1"
                class="absolute inset-y-0 end-0 flex w-11 items-center justify-center text-muted
                       hover:text-brand"
                :aria-label="show ? 'পাসওয়ার্ড লুকান' : 'পাসওয়ার্ড দেখান'">
            <x-ui.icon name="eye" class="h-4.5 w-4.5" />
        </button>
    </div>

    @error($name)
        <p id="{{ $id }}-error" class="mt-1.5 text-xs font-medium text-brand">{{ $message }}</p>
    @else
        @if ($hint)<p class="mt-1.5 text-xs text-muted">{{ $hint }}</p>@endif
    @enderror
</div>
