<?php
declare(strict_types=1);

/**
 * SocialFlow - Background Scheduler Worker CLI
 * Can be executed via Windows Task Scheduler, Powershell loop, or Linux cron.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../workers/SchedulerWorker.php';

Config::load(__DIR__ . '/../.env');

$worker = new SchedulerWorker();
$stats = $worker->run();

$output = sprintf(
    "[%s UTC] Worker [%s] executed. Found: %d, Claimed: %d, Completed: %d, Retried: %d, Failed: %d, Stale Released: %d\n",
    gmdate('Y-m-d H:i:s'),
    $stats['worker_id'],
    $stats['found'],
    $stats['claimed'],
    $stats['completed'],
    $stats['retried'],
    $stats['failed'],
    $stats['stale_released']
);

echo $output;
