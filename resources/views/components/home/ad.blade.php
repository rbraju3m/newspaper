@props(['block', 'data' => null])

@php
    $position = $block->options['position']
        ?? ($block->column === 'sidebar' ? 'sidebar_rectangle' : 'home_billboard');
@endphp

<x-ui.ad-slot :position="$position" class="mx-auto w-full rounded-lg" />
