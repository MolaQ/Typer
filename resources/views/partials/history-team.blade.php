{{-- Nazwa zespołu w historii rozgrywek: link do profilu, bot kursywą. Zmienne: $team (name, url, bot), $strong. --}}
@if ($team['url'])
    <a href="{{ $team['url'] }}" wire:navigate class="{{ $team['bot'] ? 'italic' : '' }} {{ $strong ? 'font-bold' : 'font-medium' }} hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $team['name'] }}</a>
@else
    <span class="{{ $team['bot'] ? 'italic' : '' }} {{ $strong ? 'font-bold' : 'font-medium' }}">{{ $team['name'] }}</span>
@endif
