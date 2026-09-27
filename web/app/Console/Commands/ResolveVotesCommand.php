<?php

namespace App\Console\Commands;

use App\Services\Media\ApprovalService;
use Illuminate\Console\Command;

class ResolveVotesCommand extends Command
{
    protected $signature = 'pedigree:resolve-votes';

    protected $description = 'Finalize media approval votes that passed the voting deadline';

    public function handle(ApprovalService $approvals): int
    {
        $count = $approvals->resolveStale();
        $this->info("{$count} item(s) resolved.");

        return self::SUCCESS;
    }
}
