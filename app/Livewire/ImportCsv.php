<?php

namespace App\Livewire;

use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Account;
use App\Services\BankCsvParser;
use App\Services\SubscriptionDetector;
use App\Services\TransactionImporter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('components.layouts.app')]
class ImportCsv extends Component
{
    use TogglesPrivateMode;
    use WithFileUploads;

    public $file = null;

    public ?int $accountId = null;

    public ?array $result = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->accountId = Account::first()?->id;
    }

    public function import(TransactionImporter $importer, SubscriptionDetector $detector): void
    {
        $this->reset('result', 'error');

        try {
            $this->validate([
                'file' => 'required|file|mimes:csv,txt|max:10240',
                'accountId' => 'required|exists:accounts,id',
            ]);

            try {
                $account = Account::findOrFail($this->accountId);
                $this->result = $importer->import($account, file_get_contents($this->file->getRealPath()));
                $this->result['subscriptions'] = $detector->detect();
            } catch (Throwable $e) {
                $this->error = __('Import failed: :reason', ['reason' => $e->getMessage()]);
            }
        } finally {
            // Le relevé ne reste pas dans le dossier temporaire de Livewire (purgé
            // seulement au téléversement suivant, après 24 h) : supprimé tout de
            // suite, import réussi, raté ou refusé par la validation.
            if ($this->file instanceof TemporaryUploadedFile) {
                $this->file->delete();
            }
            $this->reset('file');
        }
    }

    public function render(BankCsvParser $parser)
    {
        return view('livewire.import-csv', [
            'accounts' => Account::all(),
            'parsers' => $parser->parsers(),
        ])->title(__('CSV import'));
    }
}
