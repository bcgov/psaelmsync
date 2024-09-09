<?php
require_once('../../config.php');
require_login();

global $DB;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/dashboard-intake.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('intakerunhistory', 'local_psaelmsync'));
$PAGE->set_heading(get_string('intakerunhistory', 'local_psaelmsync'));

echo $OUTPUT->header();

?>

<!-- Tabbed Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard.php">Learner Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-courses.php">Course Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/dashboard-intake.php">Intake Run Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/manual-complete.php">Manual Complete</a>
    </li>
</ul>

<?php

// Setup pagination variables
$perpage = optional_param('perpage', 100, PARAM_INT); // Number of records per page
$page = optional_param('page', 0, PARAM_INT); // Current page number
$offset = $page * $perpage; // Calculate the offset for SQL query

// Get the total count of records
$totalcount = $DB->count_records_select('local_psaelmsync_runs', '');

// SQL to get the most recent records with pagination.
$sql = "SELECT * FROM {local_psaelmsync_runs} ORDER BY endtime DESC";
$lastruns = $DB->get_records_sql($sql, null, $offset, $perpage);

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
            maintainAspectRatio: false,
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
    <div class="col-md-12">
        <p>The cron job for intake (from CData) happens every 10 minutes, on the 5, 
            (ELM posts to CData every 10 minutes on the 0)
            between the hours of 06:00 and 18:00; 
            09:05, 09:15, 09:25, 09:35, etc.</p>
        <p>At most, there will be 72 intake runs a day (6 an hour for 12 hours).</p>
        <p>This page shows the past 100 runs, with each page representing about
            a day and a half worth of enrolment day.</p>
        <div style="height: 320px;">
            <canvas id="runsChart"></canvas>
        </div>

        <!-- Results Table -->
        <table class="table table-striped table-bordered mt-4">
            <thead>
                <tr>
                    <th>Date and Time</th>
                    <th>Enrolments</th>
                    <th>Drops</th>
                    <th>Errors</th>
                    <th>Skipped</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($lastruns as $run): ?>
            <?php
            $start = (int) $run->starttime / 1000;
            $end = (int) $run->endtime / 1000;
            ?>
            <tr>
                <td>
                    <a href="/local/psaelmsync/dashboard.php?search=<?= urlencode(date('Y-m-d H:i:s', (int) $start)) ?>">
                        <?= date('Y-m-d H:i:s', (int) $start) ?> - <?= date('H:i:s', (int) $end) ?>
                    </a>
                </td>
                <td><?= $run->enrolcount ?></td>
                <td><?= $run->suspendcount ?></td>
                <td><?= $run->errorcount ?></td>
                <td><?= $run->skippedcount ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>

        <!-- Pagination controls -->
        <?php
        echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
        ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
