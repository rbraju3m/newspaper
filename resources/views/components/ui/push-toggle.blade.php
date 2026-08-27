@props(['compact' => false])

{{--
    Breaking-news notifications for this browser.

    Renders nothing at all unless the store says it can work — no Push API, no
    service worker, or no VAPID key in the page means the control would be
    decoration. `x-cloak` keeps it from flashing in before Alpine has decided.

    A reader who has already refused is shown an explanation rather than a
    switch: `Notification.requestPermission()` never prompts a second time, so
    a toggle at that point is a button that does nothing.
--}}
<div x-data x-cloak x-show="$store.push.supported" {{ $attributes }}>
    <template x-if="$store.push.blocked">
        <p @class([
            'flex items-start gap-2 text-muted',
            'text-xs' => $compact,
            'text-sm' => ! $compact,
        ])>
            <x-ui.icon name="bell-off" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>নোটিফিকেশন ব্লক করা আছে। ব্রাউজারের সাইট সেটিংস থেকে অনুমতি দিন।</span>
        </p>
    </template>

    <template x-if="! $store.push.blocked">
        <button type="button"
                @click="$store.push.toggle()"
                :disabled="$store.push.busy"
                :aria-pressed="$store.push.subscribed ? 'true' : 'false'"
                @class([
                    'group inline-flex items-center gap-2.5 rounded-lg border border-line-strong',
                    'bg-surface font-semibold text-ink transition',
                    'hover:border-brand hover:text-brand focus-visible:border-brand',
                    'disabled:cursor-wait disabled:opacity-60',
                    'px-3 py-2 text-xs' => $compact,
                    'px-4 py-2.5 text-sm' => ! $compact,
                ])>
            <span class="relative flex h-4 w-4 shrink-0 items-center justify-center">
                <x-ui.icon name="bell" class="h-4 w-4" x-show="! $store.push.subscribed" />
                <x-ui.icon name="check" class="h-4 w-4 text-brand" x-show="$store.push.subscribed" />
            </span>

            <span x-text="$store.push.subscribed
                ? 'ব্রেকিং অ্যালার্ট চালু আছে'
                : 'ব্রেকিং নিউজ অ্যালার্ট পান'">ব্রেকিং নিউজ অ্যালার্ট পান</span>
        </button>
    </template>
</div>
