<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php'); // Include lib.php
require_login();

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/manual-complete.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('queryapi', 'local_psaelmsync'));
$PAGE->set_heading(get_string('queryapi', 'local_psaelmsync'));

$apiurl = get_config('local_psaelmsync', 'apiurl'); // Fetch the API URL from plugin settings
$apitoken = get_config('local_psaelmsync', 'apitoken');

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
        <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/manual-complete.php">Manual Complete</a>
    </li>
</ul>

<p>Coming soon.</p>



<?php echo $OUTPUT->footer();
