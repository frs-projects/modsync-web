<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('modsync:check-updates')->dailyAt('05:00')->withoutOverlapping();
