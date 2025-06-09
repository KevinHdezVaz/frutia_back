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
        $schedule->command('matches:cancel-incomplete')->everyFiveMinutes();
        $schedule->command('mvp:reset-counters')->monthlyOn(1, '00:00');

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
