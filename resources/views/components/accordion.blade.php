{{-- Akordeon na natywnym <details> (Flux free nie ma akordeonu). $items: lista [pytanie, odpowiedź].
     W odpowiedzi dozwolony prosty HTML z tłumaczeń (bez danych od użytkowników). --}}
@props(['items' => [], 'name' => null])

<div {{ $attributes->class('divide-y divide-zinc-200 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900') }}>
    @foreach ($items as [$title, $body])
        <details class="group" @if ($name) name="{{ $name }}" @endif>
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-medium text-zinc-800 transition hover:bg-lech-50 group-open:bg-lech-50 group-open:text-lech-800 dark:text-zinc-100 dark:hover:bg-lech-950 dark:group-open:bg-lech-950 dark:group-open:text-lech-200 [&::-webkit-details-marker]:hidden">
                <span>{{ $title }}</span>
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 transition duration-200 group-open:rotate-180 group-open:bg-lech-700 group-open:text-white dark:bg-zinc-800">
                    <flux:icon.chevron-down variant="micro" />
                </span>
            </summary>
            <div class="px-5 pb-5 pt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                {!! $body !!}
            </div>
        </details>
    @endforeach
</div>
