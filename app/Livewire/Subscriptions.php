<?php

namespace App\Livewire;

use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Subscription;
use App\Services\SubscriptionDetector;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Subscriptions extends Component
{
    use TogglesPrivateMode;

    public function setStatus(Subscription $subscription, string $status): void
    {
        if (in_array($status, ['active', 'to_cancel', 'cancelled', 'ignored'])) {
            $subscription->changeStatus($status);
        }
    }

    /** Prélevé après résiliation : c'est bien une reprise, l'abonnement reste actif. */
    public function confirmResumed(Subscription $subscription): void
    {
        $subscription->changeStatus('active');
    }

    /** Prélevé après résiliation par erreur : il repart en résilié, le prochain prélèvement alertera à nouveau. */
    public function keepCancelled(Subscription $subscription): void
    {
        $subscription->changeStatus('cancelled');
    }

    public function rename(Subscription $subscription, string $label): void
    {
        $label = trim($label);
        if ($label !== '') {
            $subscription->update(['label' => mb_substr($label, 0, 100)]);
        }
    }

    public function redetect(SubscriptionDetector $detector): void
    {
        $detector->detect();
    }

    public function render()
    {
        $subs = Subscription::with('category')
            ->orderByDesc('avg_amount_cents')
            ->get()
            ->groupBy(fn ($s) => in_array($s->status, ['cancelled', 'ignored']) ? 'archived' : 'active');

        $active = $subs->get('active', collect());

        return view('livewire.subscriptions', [
            'resumed' => $active->whereNotNull('resumed_on')->values(),
            'active' => $active,
            'archived' => $subs->get('archived', collect()),
            'monthlyTotal' => $active->sum(fn ($s) => $s->monthlyCostCents()),
            'toCancelSavings' => $active->where('status', 'to_cancel')->sum(fn ($s) => $s->monthlyCostCents()),
        ])->title(__('Subscriptions'));
    }
}
