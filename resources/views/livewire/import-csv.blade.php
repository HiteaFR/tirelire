<div>
    <x-topbar :title="__('Import a statement')" />

    <div class="space-y-4">
        <form wire:submit="import" class="card space-y-4 p-4">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-ink-2">{{ __('Account') }}</label>
                <select wire:model="accountId" class="field">
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->name }} @if($acc->bank) ({{ $acc->bank }}) @endif</option>
                    @endforeach
                </select>
                @if ($accounts->isEmpty())
                    <p class="mt-1 text-xs text-amber-300">{{ __('No account —') }} <a href="{{ route('accounts') }}" wire:navigate class="underline">{{ __('create one first') }}</a>.</p>
                @endif
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-ink-2">{{ __('CSV file') }}</label>
                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-dashed border-white/15 bg-surface-2 p-6 text-center transition has-[:focus]:border-brand">
                    <svg class="size-8 text-brand" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                    <span class="text-sm text-ink-2">
                        <span wire:loading.remove wire:target="file">{{ $file ? $file->getClientOriginalName() : __('Choose a CSV file') }}</span>
                        <span wire:loading wire:target="file">{{ __('Uploading…') }}</span>
                    </span>
                    <input type="file" wire:model="file" accept=".csv,text/csv" class="sr-only">
                </label>
                @error('file') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="import,file" class="btn-primary w-full">
                <span wire:loading.remove wire:target="import">{{ __('Start import') }}</span>
                <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
            </button>
        </form>

        @if ($error)
            <div class="rounded-2xl bg-rose-400/10 p-4 text-sm text-rose-300 ring-1 ring-rose-400/30">{{ $error }}</div>
        @endif

        @if ($result)
            <div class="rounded-2xl bg-brand/10 p-4 text-sm ring-1 ring-brand/30">
                <p class="font-semibold text-brand">✓ {{ __('Import complete') }}</p>
                <ul class="mt-2 space-y-1 text-ink-2">
                    <li>• {{ trans_choice(':count new operation|:count new operations', $result['imported']) }}</li>
                    <li>• {{ trans_choice(':count duplicate skipped|:count duplicates skipped', $result['skipped']) }}</li>
                    @if ($result['reconciled'] > 0)
                        <li>• {{ trans_choice('{1} :count manual entry replaced by the bank line|[2,*] :count manual entries replaced by the bank line', $result['reconciled']) }}</li>
                    @endif
                    <li>• {{ trans_choice(':count recurring payment detected|:count recurring payments detected', $result['subscriptions']) }}</li>
                </ul>
                <a href="{{ route('dashboard') }}" wire:navigate class="mt-3 inline-block font-medium text-brand">{{ __('See the dashboard') }} →</a>
            </div>
        @endif

        <section class="card p-4 text-xs text-ink-3">
            <p class="mb-2 text-sm font-semibold text-ink">{{ __('Automatically recognized formats') }}</p>
            <ul class="space-y-1.5">
                @foreach ($parsers as $parser)
                    <li><strong class="text-ink-2">{{ $parser->name() }}</strong> — {{ $parser->exportHint() }}</li>
                @endforeach
            </ul>
            <p class="mt-2">{{ __('Re-importing an overlapping file does not create duplicates.') }}</p>
        </section>
    </div>
</div>
