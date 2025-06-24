<?php

namespace App\Console;

use App\Console\Commands\ResetMvpCounters;
use Illuminate\Console\Scheduling\Schedule;
use App\Console\Commands\CancelIncompleteMatches;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected $commands = [
        CancelIncompleteMatches::class,
        ResetMvpCounters::class,

    ];

    protected function schedule(Schedule $schedule): void
    {   
        // $schedule->command('inspire')->hourly();
        $schedule->command('notifications:process')->everyMinute();
        $schedule->command('queue:work --stop-when-empty')->everyMinute();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
