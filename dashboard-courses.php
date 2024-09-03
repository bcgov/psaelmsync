<?php
require_once('../../config.php');
require_login();

global $DB, $OUTPUT, $PAGE;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/dashboard-courses.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('courseenrolstats', 'local_psaelmsync'));
$PAGE->set_heading(get_string('courseenrolstats', 'local_psaelmsync'));

echo $OUTPUT->header();

// SQL to select all courses with an IDNumber
$courses = $DB->get_records_sql("SELECT id, fullname, idnumber FROM {course} WHERE idnumber IS NOT NULL AND idnumber <> '' ORDER BY fullname ASC");

?>

<!-- Page Heading -->
<h2><?php echo get_string('courseenrolstats', 'local_psaelmsync'); ?></h2>

<!-- Tabbed Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard.php">Learner Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/dashboard-courses.php">Course Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run Dashboard</a>
    </li>
</ul>
<p>This dashboard is a work in progress. It is only meant to give a count of the 
    log records that have come through CData and does not reflect the actual number
    of enrolments in a given course.</p>
<!-- Results Table -->
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th><?php echo get_string('course', 'local_psaelmsync'); ?></th>
            <th><?php echo get_string('idnumber', 'local_psaelmsync'); ?></th>
            <th><?php echo get_string('enrolments', 'local_psaelmsync'); ?></th>
            <th><?php echo get_string('suspends', 'local_psaelmsync'); ?></th>
            <th><?php echo get_string('errors', 'local_psaelmsync'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($courses as $course): ?>
            <?php
            // Count enrol, suspend, and error log entries for each course
            $enrolments = $DB->count_records('local_psaelmsync_logs', ['course_id' => $course->id, 'action' => 'Enrol']);
            $suspends = $DB->count_records('local_psaelmsync_logs', ['course_id' => $course->id, 'action' => 'Suspend']);
            $errors = $DB->count_records('local_psaelmsync_logs', ['course_id' => $course->id, 'status' => 'Error']);
            ?>
            <tr>
                <td><?php echo format_string($course->fullname); ?></td>
                <td><?php echo format_string($course->idnumber); ?></td>
                <td><?php echo $enrolments; ?></td>
                <td><?php echo $suspends; ?></td>
                <td><?php echo $errors; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
echo $OUTPUT->footer();
