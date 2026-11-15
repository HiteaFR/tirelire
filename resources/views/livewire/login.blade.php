<div class="flex min-h-[85vh] flex-col justify-center">
    <div class="mb-8 text-center">
        <div class="mx-auto grid size-16 place-items-center rounded-3xl bg-linear-to-br from-emerald-400 to-teal-700 text-3xl shadow-xl shadow-emerald-950/50">💶</div>
        <h1 class="mt-4 text-2xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
        <p class="mt-1 text-sm text-ink-2">{{ __('Take back control of your budget') }}</p>
    </div>

    <form wire:submit="login" class="card space-y-4 p-5">
        <div>
            <label class="mb-1.5 block text-xs font-medium text-ink-2">{{ __('Email') }}</label>
            <input type="email" wire:model="email" autocomplete="email" required class="field py-3">
        </div>
        <div>
            <label class="mb-1.5 block text-xs font-medium text-ink-2">{{ __('Password') }}</label>
            <input type="password" wire:model="password" autocomplete="current-password" required class="field py-3">
        </div>
        <label class="flex items-center gap-2 text-sm text-ink-2">
            <input type="checkbox" wire:model="remember" class="size-4 rounded accent-brand">
            {{ __('Remember me') }}
        </label>
        @error('email') <p class="text-sm text-rose-400">{{ $message }}</p> @enderror
        <button type="submit" class="btn-primary w-full py-3">{{ __('Log in') }}</button>
    </form>
</div>
