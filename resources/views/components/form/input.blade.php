@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'autocomplete' => null,
])

@php $id = $attributes->get('id') ?? 'f-'.$name; @endphp

<div>
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-semibold text-ink">
        {{ $label }}
        @if (! $required)
            <span class="font-normal text-muted">(ঐচ্ছিক)</span>
        @endif
    </label>

    <input id="{{ $id }}"
           type="{{ $type }}"
           name="{{ $name }}"
           value="{{ old($name, $value) }}"
           @if ($required) required @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
           {{ $attributes->merge([
               'class' => 'w-full rounded-lg border bg-canvas px-3.5 py-2.5 text-base text-ink
                           outline-none transition placeholder:text-muted focus:border-brand
                           focus:ring-2 focus:ring-brand/20',
           ])->class([
               'border-brand' => $errors->has($name),
               'border-line-strong' => ! $errors->has($name),
           ]) }}>

    @error($name)
        <p id="{{ $id }}-error" class="mt-1.5 text-xs font-medium text-brand">{{ $message }}</p>
    @else
        @if ($hint)
            <p class="mt-1.5 text-xs text-muted">{{ $hint }}</p>
        @endif
    @enderror
</div>
