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

// SQL to get the most recent record
$sql = "SELECT * FROM {local_psaelmsync_runs} WHERE enrolcount > 0 OR suspendcount > 0 OR errorcount > 0 ORDER BY endtime DESC LIMIT 10";
// Get the most recent record
$lastruns = $DB->get_records_sql($sql);

// Prepare data for Chart.js
$chartData = [
    'labels' => [],
    'enrolments' => [],
    'suspends' => [],
    'errors' => []
];

foreach ($lastruns as $run) {
    $start = (int) $run->starttime / 1000;
    $chartData['labels'][] = date('Y-m-d H:i:s', $start);
    $chartData['enrolments'][] = $run->enrolcount;
    $chartData['suspends'][] = $run->suspendcount;
    $chartData['errors'][] = $run->errorcount;
}

// Encode the data for use in JavaScript
$chartDataJson = json_encode($chartData);

?>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var ctx = document.getElementById('runsChart').getContext('2d');
    var chartData = <?= $chartDataJson ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Enrolments',
                    data: chartData.enrolments,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true
                },
                {
                    label: 'Suspends',
                    data: chartData.suspends,
                    borderColor: 'rgba(255, 159, 64, 1)',
                    backgroundColor: 'rgba(255, 159, 64, 0.2)',
                    fill: true
                },
                {
                    label: 'Errors',
                    data: chartData.errors,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            title: {
                display: true,
                text: 'Recent Runs Overview'
            },
            scales: {
                xAxes: [{
                    type: 'time',
                    time: {
                        unit: 'minute'
                    },
                    scaleLabel: {
                        display: true,
                        labelString: 'Time'
                    }
                }],
                yAxes: [{
                    scaleLabel: {
                        display: true,
                        labelString: 'Count'
                    },
                    ticks: {
                        beginAtZero: true
                    }
                }]
            }
        }
    });
});
</script>
<!-- Chart.js Chart -->
<div class="row mb-2">
    <div class="col-md-7">
        <div class="nav nav-pills p-2 bg-light rounded-lg">
            <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
            <a class="nav-link" href="/local/psaelmsync/trigger_sync.php">Manually trigger intake</a>
        </div>
    </div>
    <div class="col-md-7">

        <canvas id="runsChart"></canvas>

        <details class="p-3">
            <summary>Intake run history</summary>
            <p>Runs where there was at least 1 enrolment or drop. Searching for a timestamp will show 
                you records 2 minutes on either side of the given time.
            </p>
            
            <?php foreach($lastruns as $run): ?>
            <?php
            $start = (int) $run->starttime / 1000;
            $end = (int) $run->endtime / 1000;
            ?>
            <div class="my-2 p-3 bg-light rounded-lg">
                <div>
                    <a href="/local/psaelmsync/dashboard.php?search=<?= urlencode(date('Y-m-d H:i:s', (int) $start)) ?>">
                        <?= date('Y-m-d H:i:s', (int) $start) ?> - <?= date('H:i:s', (int) $end) ?>
                    </a>
                </div>
                <div>
                    <?php if(!empty($run->enrolcount)): ?>
                    Enrolments: <span class="badge badge-primary"><?= $run->enrolcount ?></span>
                    <?php endif ?>
                    <?php if(!empty($run->suspendcount)): ?>
                    Drops: <span class="badge badge-primary"><?= $run->suspendcount ?></span>
                    <?php endif ?>
                    <?php if(!empty($run->errorcount)): ?>
                    Errors: <span class="badge badge-danger"><?= $run->errorcount ?></span>
                    <?php endif ?>
                    <?php if(!empty($run->skippedcount)): ?>
                    Skipped: <span class="badge badge-primary"><?= $run->skippedcount ?></span>
                    <?php endif ?>
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