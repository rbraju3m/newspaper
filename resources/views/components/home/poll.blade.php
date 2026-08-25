@props(['block', 'data'])

@if ($data)
    {{-- Votes post via fetch and swap to results in place — no page reload,
         and the results stay visible after voting. --}}
    <section class="rounded-xl border border-line bg-surface p-4"
             x-data="{
                 poll: {{ Js::from([
                     'id' => $data->id,
                     'total' => $data->votes_count,
                     'options' => $data->options->map(fn ($o) => [
                         'id' => $o->id, 'label' => $o->label,
                         'votes' => $o->votes_count, 'percentage' => $o->percentage($data->votes_count),
                     ])->all(),
                 ]) }},
                 selected: null,
                 voted: false,
                 busy: false,
                 error: '',
                 async submit() {
                     if (!this.selected || this.busy) return;
                     this.busy = true; this.error = '';
                     try {
                         const res = await fetch('{{ route('polls.vote', $data) }}', {
                             method: 'POST',
                             headers: {
                                 'Content-Type': 'application/json',
                                 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                             },
                             body: JSON.stringify({ option_id: this.selected }),
                         });
                         const body = await res.json();
                         if (body.results) { this.poll = { ...this.poll, ...body.results }; this.voted = true; }
                         if (!res.ok && body.message) this.error = body.message;
                     } catch { this.error = 'ভোট পাঠানো যায়নি, আবার চেষ্টা করুন।'; }
                     finally { this.busy = false; }
                 },
             }">
        <x-ui.section-header title="পাঠক জরিপ" />

        <p class="font-headline text-base font-semibold leading-snug text-ink">{{ $data->question }}</p>

        <div class="mt-3 space-y-2">
            <template x-for="option in poll.options" :key="option.id">
                <div>
                    <label x-show="!voted"
                           class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-line
                                  px-3 py-2 text-sm transition hover:border-brand"
                           :class="selected === option.id && 'border-brand bg-brand-50 dark:bg-transparent'">
                        <input type="radio" name="poll-option" class="accent-[var(--color-brand)]"
                               :value="option.id" x-model.number="selected">
                        <span x-text="option.label" class="text-body"></span>
                    </label>

                    <div x-show="voted" x-cloak class="mb-2">
                        <div class="flex items-center justify-between text-sm">
                            <span x-text="option.label" class="text-body"></span>
                            <span class="lat font-semibold text-ink" x-text="option.percentage + '%'"></span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-2">
                            <div class="h-full rounded-full bg-brand transition-all duration-700"
                                 :style="`width: ${option.percentage}%`"></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-brand"></p>

        <button type="button" x-show="!voted" @click="submit()" :disabled="!selected || busy"
                class="mt-3 w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white
                       transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
            <span x-show="!busy">ভোট দিন</span>
            <span x-show="busy" x-cloak>পাঠানো হচ্ছে…</span>
        </button>

        <p x-show="voted" x-cloak class="mt-3 text-center text-xs text-muted">
            মোট ভোট: <span class="lat font-semibold" x-text="poll.total"></span>
        </p>
    </section>
@endif
