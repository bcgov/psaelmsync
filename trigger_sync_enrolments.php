<?php
require_once('../../config.php');
require_login();

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/trigger_sync_enrolments.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('syncenrolments', 'local_psaelmsync'));
$PAGE->set_heading(get_string('syncenrolments', 'local_psaelmsync'));

echo $OUTPUT->header();

// Find courses with the 'completion_opt_in' custom field checked
$sql = "
    SELECT c.id, c.fullname, c.idnumber
    FROM course c
    JOIN customfield_data cfd ON cfd.instanceid = c.id
    JOIN customfield_field cff ON cff.id = cfd.fieldid
    WHERE cff.shortname = 'completion_opt_in' 
    AND cfd.intvalue = 1
    ORDER BY c.fullname ASC
";

$courses = $DB->get_records_sql($sql);

if (!$courses) {
    echo $OUTPUT->notification($sql, 'notifyproblem');
    echo $OUTPUT->notification(get_string('nocourses', 'local_psaelmsync'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Prepare a counter for inserted records
$inserted = 0;

foreach ($courses as $course) {
    // Get all enrolled users in this course
    $enrolled_users = get_enrolled_users(context_course::instance($course->id));

    // Fetch the 'itemcode' custom field for this course
    $itemcode = $DB->get_field_sql("
        SELECT cfd.value
        FROM {customfield_data} cfd
        JOIN {customfield_field} cff ON cff.id = cfd.fieldid
        WHERE cfd.instanceid = :courseid
        AND cff.shortname = 'itemcode'
    ", ['courseid' => $course->id]);

    foreach ($enrolled_users as $user) {
        // Check if the user has completed the course
        $completion = new completion_info($course);
        $is_complete = $completion->is_course_complete($user->id);

        if (!$is_complete) {
            // Check if the record already exists in local_psaelmsync_enrol
            $exists = $DB->record_exists('local_psaelmsync_enrol', [
                'course_id' => $course->id,
                'userid' => $user->id
            ]);

            if (!$exists) {
                // Insert record into local_psaelmsync_enrol
                $enrid = floor(microtime(true) * 1000);
                $record = new stdClass();
                $record->course_id = $course->idnumber;
                $record->user_id = $user->id;
                $record->enrol_status = 'Enrol';
                $record->elm_enrolment_id = $enrid;
                $record->class_code = $itemcode; // Use the custom field 'itemcode' as the class code
                $record->sha256hash = hash('sha256', $user->id . $course->id);
                $record->timecreated = time();
                $record->timemodified = time();

                $DB->insert_record('local_psaelmsync_enrol', $record);
                $inserted++;
            }
        }
    }
}

// Display success message with the count of inserted records
echo $OUTPUT->notification($inserted . ' enrolment records successfully synced.', 'notifysuccess');

// Footer output
echo $OUTPUT->footer();
