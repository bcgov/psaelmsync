<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php'); // Include lib.php
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
    $class_code = $DB->get_field_sql("
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
            // Check if the record already exists in local_psaelmsync_logs
            $exists = $DB->record_exists('local_psaelmsync_logs', [
                'elm_course_id' => $course->idnumber,
                'user_id' => $user->id
            ]);

            if (!$exists) {

                // Query the enrolment table for the enrolment date.
                $sql = "SELECT ue.timecreated 
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON ue.enrolid = e.id
                        WHERE ue.userid = :userid AND e.courseid = :courseid";

                $params = [
                    'userid' => $user->id,
                    'courseid' => $course->id,
                ];

                // Get the enrolment time for this user.
                $enrolment_info = $DB->get_record_sql($sql, $params);

                if ($enrolment_info) {
                    $enrolment_date = userdate($enrolment_info->timecreated); // Format date
                    // echo "User: {$user->firstname} {$user->lastname}, Enrolment Date: {$enrolment_date}<br>";
                }

                $record_id = time();
                $hash = hash('sha256', $user->id . $course->id . $course->idnumber);

                // make it up for now.
                $enrolment_id = floor(microtime(true) * 1000);

                log_record($record_id, 
                            $hash,
                            $enrolment_date, 
                            $course->id, 
                            $course->idnumber, 
                            $class_code, 
                            $enrolment_id, 
                            $user->id, 
                            $user->firstname, 
                            $user->lastname, 
                            $user->email, 
                            $user->idnumber,
                            'Imported', 
                            'Success');


                $inserted++;
            }
        }
    }
}

// Display success message with the count of inserted records
echo $OUTPUT->notification($inserted . ' enrolment records successfully synced.', 'notifysuccess');

// Footer output
echo $OUTPUT->footer();
