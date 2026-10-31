<?php
declare(strict_types=1);

// Pledge reminders are now sent automatically by cron/member_notifications.php:
// Saturday from 8 am (by each member's chosen channels) and a short push on Sunday from 7 am.
// This job no longer sends anything, so members are not reminded twice; remove it from the crontab.
// Admins can still send reminders by hand from the Pledges page.

echo "Pledge reminders now run from cron/member_notifications.php; remove this job from the crontab." . PHP_EOL;
