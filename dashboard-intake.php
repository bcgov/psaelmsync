<?php
require_once('../../config.php');
require_login();

global $DB;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/intake_run_history.php');
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
        <a class="nav-link" href="/local/psaelmsync/dashboard.php">Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/dashboard-intake.php">Intake Run History</a>
    </li>
</ul>

<?php

// SQL to get the most recent record 72 runs in a day so limit to 100 records.
$sql = "SELECT * FROM {local_psaelmsync_runs} WHERE enrolcount > 0 OR suspendcount > 0 OR errorcount > 0 ORDER BY endtime DESC LIMIT 100";
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

            <h2>Intake run history</h2>
            <p>Runs where there was at least 1 enrolment or drop. Searching for a timestamp will show 
                you records 2 minutes on either side of the given time.
            </p>
            <div style="height: 320px;">
                <canvas id="runsChart"></canvas>
            </div>
            
            <?php foreach($lastruns as $run): ?>
            <?php
            $start = (int) $run->starttime / 1000;
            $end = (int) $run->endtime / 1000;
            ?>
            <div class="my-2 p-3 bg-light rounded-lg">
                <div>
                    <a href="/local/psaelmsync/intake_run_history.php?search=<?= urlencode(date('Y-m-d H:i:s', (int) $start)) ?>">
                        <?= date('Y-m-d H:i:s', (int) $start) ?> - <?= date('H:i:s', (int) $end) ?>
                    </a>
                    - 
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

    </div>
</div>

<?php
echo $OUTPUT->footer();
?>
