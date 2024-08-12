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

$apiurl = get_config('local_psaelmsync', 'apiurl');

// SQL to get the most recent record
$sql = "SELECT * FROM {local_psaelmsync_runs} ORDER BY endtime DESC LIMIT 10";
// Get the most recent record
$lastruns = $DB->get_records_sql($sql);
?>
<div class="row mb-2">
    <div class="col-md-7">
        <div class="nav nav-pills p-2 bg-light rounded-lg">
            <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
            <a class="nav-link" href="<?= $apiurl ?>" target="_blank">Enrolment Endpoint</a>
            <a class="nav-link" href="/local/psaelmsync/trigger_sync.php">Manually trigger intake</a>
        </div>
    </div>
    <div class="col-md-7">
        <details class="p-3">
            <summary>Intake run history</summary>
            <?php foreach($lastruns as $run): ?>
            <?php
            $start = (int) $run->starttime / 1000;
            $end = (int) $run->endtime / 1000;
            ?>
            <div class="my-2 p-3 bg-light rounded-lg">
                <div>
                    <?= date('Y-m-d h:i:s', (int) $start) ?> - <?= date('h:i:s', (int) $end) ?>
                </div>
                <div>
                    Enrolments: <span class="badge badge-primary"><?= $run->enrolcount ?></span>
                    Drops: <span class="badge badge-primary"><?= $run->suspendcount ?></span>
                </div>
            </div>
            <?php endforeach ?>
        </details>
    </div>
</div>

<!-- Search Form -->
<form method="get" action="dashboard.php" class="mb-3">
    <div class="input-group">
        <input type="text" name="search" value="<?= s(optional_param('search', '', PARAM_RAW)) ?>" class="form-control" placeholder="<?= get_string('search', 'local_psaelmsync') ?>">
        <div class="input-group-append">
            <button class="btn btn-primary" type="submit"><?= get_string('search', 'local_psaelmsync') ?></button>
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
    $conditions[] = $DB->sql_like('course_name', ':search1', false);
    $conditions[] = $DB->sql_like('user_firstname', ':search2', false);
    $conditions[] = $DB->sql_like('user_lastname', ':search3', false);
    $conditions[] = $DB->sql_like('user_guid', ':search4', false);
    $conditions[] = $DB->sql_like('user_email', ':search5', false);
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
    $params['search4'] = "%$search%";
    $params['search5'] = "%$search%";
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
