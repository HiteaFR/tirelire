<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💶</text></svg>">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ground text-ink antialiased">
    <div class="mx-auto max-w-lg px-4 pb-28">
        {{ $slot }}
    </div>

    @auth
    <nav class="fixed inset-x-0 bottom-0 z-40" style="padding-bottom: env(safe-area-inset-bottom)">
        <div class="mx-auto mb-3 max-w-lg px-4">
            <div class="grid grid-cols-5 rounded-3xl bg-surface/90 p-1.5 shadow-2xl shadow-black/60 ring-1 ring-white/10 backdrop-blur-xl">
                @foreach ([
                    ['dashboard', __('Home'), 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
                    ['transactions', __('Operations'), 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'],
                    ['import', __('Import'), 'M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z'],
                    ['subscriptions', __('Subs'), 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99'],
                    ['budgets', __('Budgets'), 'M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z'],
                ] as [$route, $label, $path])
                    @php $active = request()->routeIs($route); @endphp
                    <a href="{{ route($route) }}" wire:navigate
                       class="flex flex-col items-center gap-1 rounded-2xl py-2 text-[10px] font-medium transition {{ $active ? 'bg-white/10 text-brand' : 'text-ink-3' }}">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="{{ $active ? 2 : 1.5 }}" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
                        </svg>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>
    @endauth
</body>
</html>
