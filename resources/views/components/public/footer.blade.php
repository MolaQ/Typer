<footer class="sticky bottom-0 z-10 h-12 border-t border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
    <div class="flex h-full items-center justify-between gap-4 px-4 text-sm text-zinc-500 sm:px-6 lg:px-8">
        <div class="flex items-center gap-x-4 whitespace-nowrap">
            <span>&copy; {{ now()->year }} {{ config('app.name') }}</span>
            <span>v{{ config('app.version', '0.1.0') }}</span>
            <span class="hidden sm:inline">{{ now()->translatedFormat('j F Y') }}</span>
        </div>

        <x-public.quote class="hidden max-w-md truncate md:block" />
    </div>
</footer>