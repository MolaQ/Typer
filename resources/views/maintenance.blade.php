{{--
    Przerwa techniczna (tryb konserwacji z panelu, App\Support\Maintenance): widzą ją wszyscy poza adminami.
    Zmienne: $state (message, until…), $until (Carbon|null).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
    <main class="flex min-h-svh items-center justify-center p-4">
        <div class="w-full max-w-lg overflow-hidden rounded-2xl shadow-xl">
            <div class="lech-banner flex flex-col items-center gap-4 px-6 py-10 text-center text-white">
                <x-app-logo-icon class="size-14 fill-current" />
                <div class="text-xs font-bold uppercase tracking-[0.2em] text-white/70">LechTYPER</div>
                <h1 class="text-2xl font-bold">{{ __('Technical break') }}</h1>
                <p class="text-sm text-white/80">{{ __('We are updating the site and adding new features. Your tips and results are safe.') }}</p>
            </div>
            <div class="space-y-4 bg-white px-6 py-6 text-center dark:bg-zinc-900">
                @if ($state['message'] !== '')
                    <p class="whitespace-pre-line text-zinc-700 dark:text-zinc-200">{{ $state['message'] }}</p>
                @endif
                @if ($until && $until->isFuture())
                    <p class="text-sm text-zinc-500">{{ __('We plan to be back on :date.', ['date' => $until->translatedFormat('l, j F, H:i')]) }}</p>
                @else
                    <p class="text-sm text-zinc-500">{{ __('We will be back shortly.') }}</p>
                @endif
                <div class="flex justify-center gap-3 text-xs">
                    <a href="{{ url()->current() }}" class="rounded-lg bg-lech-700 px-4 py-2 font-semibold text-white hover:bg-lech-800">{{ __('Try again') }}</a>
                    @guest
                        <a href="{{ route('login') }}" class="rounded-lg border border-zinc-200 px-4 py-2 font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300">{{ __('Admin login') }}</a>
                    @endguest
                </div>
            </div>
        </div>
    </main>
</body>
</html>
