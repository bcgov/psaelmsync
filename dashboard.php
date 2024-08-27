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
?>

<!-- Tabbed Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/dashboard.php">Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run History</a>
    </li>
</ul>
<!-- Search Form -->
<form method="get" action="dashboard.php" class="my-3">
    <div class="input-group">
        <input type="text" name="search" value="<?= s(optional_param('search', '', PARAM_RAW)) ?>" class="form-control" placeholder="<?= get_string('search', 'local_psaelmsync') ?>">
        <div class="input-group-append">
            <button class="btn btn-primary" type="submit"><?= get_string('search', 'local_psaelmsync') ?></button>
            <a href="/local/psaelmsync/dashboard.php" class="btn btn-secondary">Clear</a>
        </div>
        
    </div>
</form>
<?php
// Get the search query.
$search = optional_param('search', '', PARAM_RAW);

$table = new \local_psaelmsync\output\log_table('psaelmsync_log_table');

// Define the SQL query for fetching the logs with search functionality.
$conditions = [];
$params = [];

if (!empty($search)) {
   // Check if the search string is a valid ISO8601 date
   $timestamp = strtotime($search);
   if ($timestamp !== false) {
       // Convert the timestamp to Unix time.
       $conditions[] = 'timestamp BETWEEN :start AND :end';
       $params['start'] = $timestamp - 120;
       $params['end'] = $timestamp + 120; // + 100 seconds to capture the whole run
   } else {
        $conditions[] = $DB->sql_like('status', ':search1', false);
        $conditions[] = $DB->sql_like('course_name', ':search2', false);
        $conditions[] = $DB->sql_like('user_firstname', ':search3', false);
        $conditions[] = $DB->sql_like('user_lastname', ':search4', false);
        $conditions[] = $DB->sql_like('user_guid', ':search5', false);
        $conditions[] = $DB->sql_like('user_email', ':search6', false);
        $conditions[] = $DB->sql_like('elm_enrolment_id', ':search7', false);
        $conditions[] = $DB->sql_like('action', ':search8', false);
        $params['search1'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "%$search%";
        $params['search4'] = "%$search%";
        $params['search5'] = "%$search%";
        $params['search6'] = "%$search%";
        $params['search7'] = "%$search%";
        $params['search8'] = "%$search%";
   }
}

$where = !empty($conditions) ? implode(' OR ', $conditions) : '1=1';
$table->set_sql('*', '{local_psaelmsync_logs}', $where, $params);

// Define the base URL for the table.
$table->define_baseurl($PAGE->url);

// Set the per page limit.
$perpage = 20; // Adjust the number of logs per page as needed.
$totalrows = $DB->count_records_select('local_psaelmsync_logs', $where, $params);
$table->pagesize($perpage, $totalrows);

// Setup and display the table.
$table->out($perpage, true);

echo $OUTPUT->footer();
