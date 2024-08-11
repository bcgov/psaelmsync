<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/psaelmsync/classes/log_table.php');
require_login();

global $DB;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/dashboard.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('logs', 'local_psaelmsync'));
$PAGE->set_heading(get_string('logs', 'local_psaelmsync'));

echo $OUTPUT->header();

$sql = 'SELECT timecreated FROM {local_psaelmsync_runs} ORDER BY timecreated DESC LIMIT 1';
$lastrun = $DB->get_field_sql($sql);
echo 'Last run: ' . date('Y-m-d h:i:s', $lastrun);

$apiurl = get_config('local_psaelmsync', 'apiurl');
echo '<div class="my-3"><a href="/admin/settings.php?section=local_psaelmsync">Settings</a> | ';
echo '<a href="' . $apiurl . '" target="_blank">Enrolment Endpoint</a> | ';
echo '<a href="/local/psaelmsync/trigger_sync.php">Manually trigger intake</a></div>';

$table = new \local_psaelmsync\output\log_table('psaelmsync_log_table');
// Define the SQL query for fetching the logs.
$countsql = 'SELECT COUNT(1) FROM {local_psaelmsync_logs}';
$table->set_sql('*', '{local_psaelmsync_logs}', '1=1');

// Define the base URL for the table.
$table->define_baseurl($PAGE->url);

// Set the per page limit.
$perpage = 20; // Adjust the number of logs per page as needed.
$totalrows = $DB->count_records_sql($countsql);
$table->pagesize($perpage, $totalrows);

// Setup and display the table.
$table->out($perpage, true);

echo $OUTPUT->footer();
