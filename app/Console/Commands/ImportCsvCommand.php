<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\SubscriptionDetector;
use App\Services\TransactionImporter;
use Illuminate\Console\Command;

class ImportCsvCommand extends Command
{
    protected $signature = 'finance:import
        {file : Path of the bank CSV file}
        {--account= : Target account ID (default: first account, created if there is none)}
        {--name= : Name of the account if it has to be created (default: "Current account", translated)}';

    protected $description = 'Import a bank CSV export into an account, then detect recurring payments again';

    public function handle(TransactionImporter $importer, SubscriptionDetector $detector): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error(__('Unreadable file: :path', ['path' => $path]));

            return self::FAILURE;
        }

        $account = $this->option('account')
            ? Account::findOrFail((int) $this->option('account'))
            : (Account::first() ?? Account::create(['name' => $this->option('name') ?: __('Current account')]));

        $result = $importer->import($account, file_get_contents($path));
        $subs = $detector->detect();

        $this->info(__('Account « :name » (#:id)', ['name' => $account->name, 'id' => $account->id]));
        $this->info(__(':imported operations imported, :skipped duplicates skipped, :reconciled manual entries replaced (out of :total lines).', $result));
        $this->info(trans_choice(':count recurring payment detected|:count recurring payments detected', $subs));

        return self::SUCCESS;
    }
}
