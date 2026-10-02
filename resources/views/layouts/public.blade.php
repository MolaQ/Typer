<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="bg-white antialiased dark:bg-zinc-800">
    @php
        // 4rem = header, 3rem = stopka. Gość nie ma headera na szerokich ekranach.
        $asideClasses = auth()->check()
            ? 'top-16 h-[calc(100dvh-7rem)]'
            : 'top-0 h-[calc(100dvh-3rem)]';
    @endphp

    <div class="flex min-h-dvh">

        {{-- Lewy sidebar: od góry do dołu ekranu, na telefonie szuflada --}}
        <x-public.left-sidebar />

        <div class="flex min-w-0 flex-1 flex-col">

            <x-public.header />

            <div class="flex flex-1">
                <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <flux:toast position="top end" class="pt-20" />
                    <x-flash-toast />

                    {{ $slot }}
                </main>

                {{-- Prawy panel: kolumna od xl wzwyż --}}
                <aside
                    class="{{ $asideClasses }} sticky hidden w-72 shrink-0 self-start overflow-y-auto border-s border-zinc-200 p-4 dark:border-zinc-700 xl:block">
                    <x-public.right-panel />
                </aside>
            </div>

            <x-public.info-panel />
            <x-public.footer />
        </div>
    </div>

    {{-- Prawy panel poniżej xl: wysuwany z prawej strony --}}
    <flux:modal name="right-panel" flyout position="right" class="w-80 max-w-full">
        <x-public.right-panel />
    </flux:modal>

    @fluxScripts
</body>

</html>