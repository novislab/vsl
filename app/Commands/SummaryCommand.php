<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;

class SummaryCommand extends Command
{
    protected $signature = 'summary';

    protected $description = 'Display the application command summary';

    protected $hidden = true;

    public function handle(DescriberContract $describer): int
    {
        $describer->describe($this->getApplication(), $this->output);

        return self::SUCCESS;
    }
}
