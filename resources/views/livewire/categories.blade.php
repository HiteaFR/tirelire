@php
    $kindLabels = ['expense' => __('Expenses'), 'income' => __('Income'), 'transfer' => __('Transfers')];
@endphp

<div>
    <x-topbar :title="__('Categories & rules')" />

    <div class="space-y-4">
        {{-- Recatégorisation des opérations existantes --}}
        <section class="card space-y-3 p-4">
            <div>
                <p class="text-sm font-semibold">{{ __('Apply the rules to existing operations') }}</p>
                <p class="mt-0.5 text-xs text-ink-3">{{ __('New imports are categorized automatically. Run this after adding or changing rules.') }}</p>
            </div>
            <label class="flex items-start gap-2 text-xs text-ink-2">
                <input type="checkbox" wire:model.live="recategorizeAll" class="mt-0.5 size-4 rounded accent-brand">
                <span>{{ __('Also operations that already have a category (overrides the categories chosen by hand)') }}</span>
            </label>
            <button type="button" wire:click="recategorize"
                    wire:confirm="{{ $recategorizeAll ? __('Recategorize all operations? Categories chosen by hand will be replaced when a rule matches.') : __('Categorize the operations that have no category?') }}"
                    wire:loading.attr="disabled" wire:target="recategorize" class="btn-primary w-full">
                {{ __('Recategorize') }}
            </button>
            @if ($recategorized !== null)
                <p class="text-xs text-brand" role="status">{{ trans_choice(':count operation recategorized.|:count operations recategorized.', $recategorized) }}</p>
            @endif
        </section>

        {{-- Catégories --}}
        <div class="space-y-2">
            @foreach ($categories as $cat)
                <div class="card overflow-hidden" wire:key="cat-{{ $cat->id }}">
                    <button type="button" wire:click="open({{ $cat->id }})" aria-expanded="{{ $openId === $cat->id ? 'true' : 'false' }}"
                            class="flex w-full items-center gap-3 p-4 text-left">
                        <x-category-icon :category="$cat" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $cat->name }}</p>
                            <p class="text-[11px] text-ink-3">
                                {{ $kindLabels[$cat->kind] ?? $cat->kind }}@if ($cat->fixed) · {{ $cat->kind === 'income' ? __('regular') : __('fixed') }}@endif
                                · {{ trans_choice(':count operation|:count operations', $cat->transactions_count) }}
                                · {{ trans_choice(':count rule|:count rules', $cat->rules_count) }}
                            </p>
                        </div>
                        <svg class="size-4 shrink-0 text-ink-3 transition {{ $openId === $cat->id ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                    </button>

                    @if ($openId === $cat->id && $openCategory)
                        <div class="space-y-5 border-t border-line p-4">
                            {{-- Modification --}}
                            <form wire:submit="updateCategory" class="space-y-2">
                                <div class="grid grid-cols-[4rem_1fr] gap-2">
                                    <input type="text" wire:model="icon" maxlength="8" aria-label="{{ __('Icon') }}" class="field text-center">
                                    <input type="text" wire:model="name" maxlength="50" aria-label="{{ __('Name') }}" required class="field">
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <select wire:model="kind" aria-label="{{ __('Type') }}" class="field">
                                        @foreach ($kindLabels as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <label class="flex items-center gap-2 text-xs text-ink-2">
                                        <input type="checkbox" wire:model="fixed" class="size-4 rounded accent-brand">
                                        {{ __('Fixed charge or regular income') }}
                                    </label>
                                </div>
                                @error('name') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                @error('icon') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                <button type="submit" class="btn-primary w-full">{{ __('Save') }}</button>
                            </form>

                            {{-- Règles --}}
                            <div class="space-y-2">
                                <p class="text-xs font-medium uppercase tracking-wider text-ink-3">{{ __('Rules') }}</p>
                                <p class="text-[11px] text-ink-3">{{ __('A rule is a text searched in the bank label, ignoring case. The lowest priority is tried first.') }}</p>

                                @forelse ($openCategory->rules as $rule)
                                    <div class="rounded-xl bg-surface-2 p-3" wire:key="rule-{{ $rule->id }}">
                                        @if ($editingRuleId === $rule->id)
                                            <form wire:submit="updateRule" class="space-y-2">
                                                <div class="grid grid-cols-[1fr_5rem] gap-2">
                                                    <input type="text" wire:model.live.debounce.300ms="editPattern" maxlength="100" aria-label="{{ __('Pattern') }}" class="field font-mono uppercase">
                                                    <input type="number" wire:model="editPriority" min="1" max="{{ \App\Livewire\Categories::MAX_PRIORITY }}" aria-label="{{ __('Priority') }}" class="field">
                                                </div>
                                                <p class="text-[11px] text-ink-3">{{ trans_choice('Matches :count existing operation.|Matches :count existing operations.', $this->matchCount) }}</p>
                                                @error('editPattern') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                                @error('editPriority') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                                <div class="flex gap-2">
                                                    <button type="submit" class="btn-primary flex-1">{{ __('Save') }}</button>
                                                    <button type="button" wire:click="cancelRuleEdit" class="chip px-4">{{ __('Cancel') }}</button>
                                                </div>
                                            </form>
                                        @else
                                            <div class="flex items-center gap-2">
                                                <code class="min-w-0 flex-1 truncate font-mono text-xs whitespace-pre">{{ $rule->pattern }}</code>
                                                <span class="num text-[11px] text-ink-3">{{ __('priority :priority', ['priority' => $rule->priority]) }}</span>
                                                <button type="button" wire:click="editRule({{ $rule->id }})" class="text-[11px] text-brand">{{ __('Edit') }}</button>
                                                <button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="{{ __('Delete this rule?') }}" class="text-[11px] text-rose-400">{{ __('Delete') }}</button>
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-xs text-ink-3">{{ __('No rule yet.') }}</p>
                                @endforelse

                                @if ($editingRuleId === null)
                                    <form wire:submit="addRule" class="space-y-2 pt-1">
                                        <div class="grid grid-cols-[1fr_5rem] gap-2">
                                            <input type="text" wire:model.live.debounce.300ms="pattern" maxlength="100" placeholder="{{ __('Text in the label, e.g. NETFLIX') }}" aria-label="{{ __('Pattern') }}" class="field font-mono uppercase">
                                            <input type="number" wire:model="priority" min="1" max="{{ \App\Livewire\Categories::MAX_PRIORITY }}" aria-label="{{ __('Priority') }}" class="field">
                                        </div>
                                        @if (trim($pattern) !== '')
                                            <p class="text-[11px] text-ink-3">{{ trans_choice('Matches :count existing operation.|Matches :count existing operations.', $this->matchCount) }}</p>
                                        @endif
                                        @error('pattern') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                        @error('priority') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                        <button type="submit" class="chip w-full justify-center py-2">{{ __('Add the rule') }}</button>
                                    </form>
                                @endif
                            </div>

                            {{-- Suppression --}}
                            <form wire:submit="deleteCategory" class="space-y-2 border-t border-line pt-4">
                                <p class="text-xs font-medium uppercase tracking-wider text-ink-3">{{ __('Delete the category') }}</p>
                                @if ($openCategory->transactions_count > 0)
                                    <label class="block text-xs text-ink-2">
                                        {{ trans_choice('Move its :count operation to:|Move its :count operations to:', $openCategory->transactions_count) }}
                                        <select wire:model="moveTo" class="field mt-1">
                                            <option value="">❓ {{ __('Uncategorized') }}</option>
                                            @foreach ($categories as $other)
                                                @continue($other->id === $cat->id)
                                                <option value="{{ $other->id }}">{{ $other->icon }} {{ $other->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                                @error('moveTo') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                <button type="submit" wire:confirm="{{ __('Delete this category and its rules?') }}" class="text-xs text-rose-400 underline decoration-dotted">{{ __('Delete') }}</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Nouvelle catégorie --}}
        <form wire:submit="createCategory" class="card space-y-3 p-4">
            <p class="text-sm font-semibold">{{ __('New category') }}</p>
            <div class="grid grid-cols-[4rem_1fr] gap-2">
                <input type="text" wire:model="newIcon" maxlength="8" placeholder="📁" aria-label="{{ __('Icon') }}" class="field text-center">
                <input type="text" wire:model="newName" maxlength="50" placeholder="{{ __('Name') }}" required class="field">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <select wire:model="newKind" aria-label="{{ __('Type') }}" class="field">
                    @foreach ($kindLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-xs text-ink-2">
                    <input type="checkbox" wire:model="newFixed" class="size-4 rounded accent-brand">
                    {{ __('Fixed charge or regular income') }}
                </label>
            </div>
            @error('newName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            @error('newIcon') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full">{{ __('Create') }}</button>
        </form>
    </div>
</div>
