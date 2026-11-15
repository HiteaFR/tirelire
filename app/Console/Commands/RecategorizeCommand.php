<?php

namespace App\Console\Commands;

use App\Services\Categorizer;
use Illuminate\Console\Command;

class RecategorizeCommand extends Command
{
    protected $signature = 'finance:recategorize
        {--pattern= : Only process operations whose label contains this text}
        {--all : Also recategorize operations that already have a category (otherwise: only uncategorized ones)}';

    protected $description = 'Apply the categorization rules again to existing operations';

    public function handle(Categorizer $categorizer): int
    {
        $updated = $categorizer->recategorize((bool) $this->option('all'), $this->option('pattern') ?: null);

        $this->info(trans_choice(':count operation recategorized.|:count operations recategorized.', $updated));

        return self::SUCCESS;
    }
}
