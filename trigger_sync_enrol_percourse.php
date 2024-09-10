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


// Prepare a counter for inserted records
$inserted = 0;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid]);
$courseidnumber = $course->idnumber;

// Get all enrolled users in this course
$enrolled_users = get_enrolled_users(context_course::instance($courseid));

// Fetch the 'itemcode' custom field for this course
$class_code = $DB->get_field_sql("
    SELECT cfd.value
    FROM {customfield_data} cfd
    JOIN {customfield_field} cff ON cff.id = cfd.fieldid
    WHERE cfd.instanceid = :courseid
    AND cff.shortname = 'itemcode'
", ['courseid' => $courseid]);

foreach ($enrolled_users as $user) {
    // Check if the user has completed the course
    $completion = new completion_info($course);
    $is_complete = $completion->is_course_complete($user->id);

    if (!$is_complete) {
        // Check if the record already exists in local_psaelmsync_logs
        $exists = $DB->record_exists('local_psaelmsync_logs', [
            'elm_course_id' => $courseidnumber,
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
                'courseid' => $courseid,
            ];

            // Get the enrolment time for this user.
            $enrolment_info = $DB->get_record_sql($sql, $params);

            if ($enrolment_info) {
                $datetime = new DateTime();
                $datetime->setTimestamp($enrolment_info->timecreated);
                $enrolment_date = $datetime->format(DateTime::ATOM); // ISO 8601 format
            }

            $record_id = time();
            $hash = hash('sha256', $user->id . $courseid . $courseidnumber);

            // make it up for now.
            $enrolment_id = floor(microtime(true) * 1000);

            // log_record($record_id, 
            //             $hash,
            //             $enrolment_date, 
            //             $courseid, 
            //             $courseidnumber, 
            //             $class_code, 
            //             $enrolment_id, 
            //             $user->id, 
            //             $user->firstname, 
            //             $user->lastname, 
            //             $user->email, 
            //             $user->idnumber,
            //             'Imported', 
            //             'Success');
            $log = new stdClass();
            $log->record_id = $record_id;
            $log->sha256hash = $hash;
            $log->record_date_created = $enrolment_date;
            $log->course_id = $courseid;
            $log->elm_course_id = $courseidnumber;
            $log->class_code = $class_code;
            $log->course_name = $course->fullname;
            $log->user_id = $user->id;
            $log->user_firstname = $user->firstname;
            $log->user_lastname = $user->lastname;
            $log->user_guid = $user->idnumber; 
            $log->user_email = $user->email;
            $log->elm_enrolment_id = $enrolment_id;
            $log->action = 'Imported';
            $log->status = 'Success';
            $log->timestamp = 1725411618;
        
            $DB->insert_record('local_psaelmsync_logs', $log);


            $inserted++;
        }
    }
}


// Display success message with the count of inserted records
echo $OUTPUT->notification($inserted . ' enrolment records successfully synced.', 'notifysuccess');

// Footer output
echo $OUTPUT->footer();
