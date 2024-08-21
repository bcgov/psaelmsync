<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2024082001;
// $plugin->requires  = 2022041900; // Moodle 4.2+.
$plugin->requires  = 2021051718; // Moodle 3.11+.
$plugin->component = 'local_psaelmsync';
$plugin->cron      = 600; // Run every 10 minutes.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1';
