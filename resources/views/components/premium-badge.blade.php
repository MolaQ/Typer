{{-- Oznaczenie treści dostępnych tylko dla kont premium (fioletowa plakietka z iskierkami). --}}
<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700 ring-1 ring-purple-200 dark:bg-purple-500/15 dark:text-purple-300 dark:ring-purple-500/30') }}>
    <flux:icon.sparkles variant="micro" class="size-3.5" />
    {{ __('Premium') }}
</span>
