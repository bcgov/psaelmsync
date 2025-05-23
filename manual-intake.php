<?php
/**
 * Manual intake. 
 * 
 * Read the CData feed directly and echo it back; do lookups on each record
 * and provide options to action them.
 * 
 * Author: Allan Haggett <allan.haggett@gov.bc.ca>
 * 
 */

global $CFG, $DB, $PAGE, $OUTPUT;

require_once('../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php'); // Include lib.php

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/manual-intake.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('queryapi', 'local_psaelmsync'));
$PAGE->set_heading(get_string('queryapi', 'local_psaelmsync'));

$apiurl = get_config('local_psaelmsync', 'apiurl'); // Fetch the API URL from plugin settings
$apitoken = get_config('local_psaelmsync', 'apitoken');

echo $OUTPUT->header();

// Initialize variables
$from = '';
$to = '';
$data = null;
$feedback = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['process'])) {
        // For security
        require_sesskey();
        // Processing a single record
        $record_date_created = required_param('record_date_created', PARAM_TEXT);
        $course_state = required_param('course_state', PARAM_TEXT);
        $elm_course_id = required_param('elm_course_id', PARAM_TEXT);
        $elm_enrolment_id = required_param('elm_enrolment_id', PARAM_TEXT);
        $user_guid = required_param('guid', PARAM_TEXT);
        $class_code = required_param('class_code', PARAM_TEXT);
        $user_email = required_param('email', PARAM_TEXT);
        $first_name = required_param('first_name', PARAM_TEXT);
        $last_name = required_param('last_name', PARAM_TEXT);

        $hash_content = $record_date_created . $elm_course_id . $class_code . $course_state . $user_guid . $user_email;
        $hash = hash('sha256', $hash_content);

        $hashcheck = $DB->get_record('local_psaelmsync_logs', ['sha256hash' => $hash], '*', IGNORE_MULTIPLE);
        // Does the hash exist in the table? 
        if ($hashcheck) {
            $feedback = 'This has already been processed.';
        }
        
        // Find the course by its idnumber (COURSE_IDENTIFIER maps to idnumber)
        $course = $DB->get_record('course', ['idnumber' => $elm_course_id]);
        if ($course) {

            // Find the user by GUID, create if they don't exist
            $user = $DB->get_record('user', ['idnumber' => $user_guid]);
            if (!$user) {
                // Create the new user if they don't exist
                $user = create_new_user($user_email, $first_name, $last_name, $user_guid);
                
                if (!$user) {
                    // We can't find them via GUID but is there an account with the same email?
                    $useremailcheck = $DB->get_record('user', ['email' => $user_email]);
                    if (!$useremailcheck) {
                        // If there is no account with the email just put out a generic error.
                        $feedback = "Failed to create a new user for GUID {$user_guid}.";
                    } else {
                        // If there is an account with the email
                        $feedback = "Failed to create a new user for GUID {$user_guid}, ";
                        $feedback .= "but there is <a href='/user/view.php?id={$useremailcheck->id}'>an account</a>";
                        $feedback .= "with that {$user_email}. Please investigate further.";
                    }
                }
            }

            if ($user) {

                // Even if we find a user by the provided GUID, we also need to check
                // to see if the email address associated with the account is consistent
                // with this CData record. If it isn't then we notify admins and error out.
                if ($user->email != $user_email) {
                    $feedback = 'There is an email mismatch happening. ';
                    // Check if another user already has the new email address
                    $useremailcheck = $DB->get_record('user', ['email' => $user_email]);
                    // We base usernames on email addresses, so we want to be double-sure
                    // and also check to ensure that the username doesn't exist. 
                    // These are almost certainly going to be the same, but weird things happen 
                    // around here so we just make sure.
                    // Generate the new username based on the new email address for comparison
                    $new_username = strtolower($user_email);
                    // If we need to optimize this process at any point, this lookup might 
                    // be considered a bit redundant.
                    $username_exists = $DB->record_exists('user', ['username' => $new_username]);

                    if (!$useremailcheck && !$username_exists) {
                        $feedback .= 'There is no existing account with that address.';
                    } else { // There IS another account with this email or username
                        $feedback .= 'There is an <a href="/user/view.php?id=' . $useremailcheck->id . '">existing account</a> with that address.';
                    }
                } // end email check

                // Get the manual enrolment plugin
                $manual_enrol = enrol_get_plugin('manual');
                $enrol_instances = enrol_get_instances($course->id, true);
                $manual_instance = null;

                // Find the manual enrolment instance for the course
                foreach ($enrol_instances as $instance) {
                    if ($instance->enrol === 'manual') {
                        $manual_instance = $instance;
                        break;
                    }
                }

                if ($manual_instance && !empty($manual_instance->roleid)) {
                    // Based on COURSE_STATE, enroll or suspend the user
                    if ($course_state === 'Enrol') {

                        // Enroll the user
                        $manual_enrol->enrol_user($manual_instance, $user->id, $manual_instance->roleid, 0, 0, ENROL_USER_ACTIVE);

                        // Invalidate the enrolment cache for the user.
                        // cache_helper::invalidate_by_definition('core', 'userenrolments', array(), array($user->id));
                        // // Invalidate the course context cache.
                        // $context = context_course::instance($course->id);
                        // $context->mark_dirty();
                        
                        // Check if enrolment was successful
                        $is_enrolled = $DB->record_exists('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual_instance->id]);
                                        
                        if ($is_enrolled) {
                            // Log it!!
                            $log = new stdClass();
                            $log->record_id = time();
                            $log->sha256hash = $hash;
                            $log->record_date_created = $record_date_created;
                            $log->course_id = $course->id;
                            $log->elm_course_id = $elm_course_id;
                            $log->class_code = $class_code;
                            $log->course_name = $course->fullname;
                            $log->user_id = $user->id;
                            $log->user_firstname = $user->firstname;
                            $log->user_lastname = $user->lastname;
                            $log->user_guid = $user->idnumber; 
                            $log->user_email = $user->email;
                            $log->elm_enrolment_id = $elm_enrolment_id;
                            $log->action = 'Manual Enrol';
                            $log->status = 'Success';
                            $log->timestamp = time();
                        
                            $DB->insert_record('local_psaelmsync_logs', $log);

                            $feedback = "User {$user->email} has been enrolled in the course.";

                            send_welcome_email($user, $course); // Send the welcome email
                            
                        } else {
                            $feedback = "Failed to enroll user {$user->email} in the course.";
                        }

                    } elseif ($course_state === 'Suspend') {
                        // Suspend the user enrolment
                        $manual_enrol->update_user_enrol($manual_instance, $user->id, ENROL_USER_SUSPENDED);

                        // Invalidate the enrolment cache for the user.
                        // cache_helper::invalidate_by_definition('core', 'userenrolments', array(), array($user->id));
                        // // Invalidate the course context cache.
                        // $context = context_course::instance($course->id);
                        // $context->mark_dirty();

                        // Log it!!
                        $log = new stdClass();
                        $log->record_id = time();
                        $log->sha256hash = $hash;
                        $log->record_date_created = $record_date_created;
                        $log->course_id = $course->id;
                        $log->elm_course_id = $elm_course_id;
                        $log->class_code = $class_code;
                        $log->course_name = $course->fullname;
                        $log->user_id = $user->id;
                        $log->user_firstname = $user->firstname;
                        $log->user_lastname = $user->lastname;
                        $log->user_guid = $user->idnumber; 
                        $log->user_email = $user->email;
                        $log->elm_enrolment_id = $elm_enrolment_id;
                        $log->action = 'Manual Suspend';
                        $log->status = 'Success';
                        $log->timestamp = time();
                    
                        $DB->insert_record('local_psaelmsync_logs', $log);

                        $feedback = "User {$user->email} has been suspended from the course.";

                    } else {

                        $feedback = "Invalid course state.";

                    }
                } else {
                    $feedback = "No manual enrolment instance found for the course.";
                }
            }
        } else {
            $feedback = "Course with identifier {$elm_course_id} not found.";
        }
    } else {
        // Handle the date range form submission (API query)

        // Build the API URL with date filters
        $from = required_param('from', PARAM_TEXT);
        $to = required_param('to', PARAM_TEXT);
        $user_emaillookup = required_param('emaillookup', PARAM_TEXT);
        $user_guidlookup = required_param('guidlookup', PARAM_TEXT);
        // #TODO Update this so that it's not either/or; able to combine email/guid 
        // with a date range.
        if(!empty($user_emaillookup)) {
            // $apiurlfiltered = $apiurl . "&filter=date_created,gt," . urlencode($from) . "&filter=date_created,lt," . urlencode($to); // MOCK API format
            $apiurlfiltered = $apiurl . "?%24orderby=COURSE_STATE_DATE,date_created+asc&%24filter=email+eq+%27" . urlencode($user_emaillookup) . "%27";
        } elseif(!empty($user_guidlookup)) {
            $apiurlfiltered = $apiurl . "?%24orderby=COURSE_STATE_DATE,date_created+asc&%24filter=GUID+eq+%27" . urlencode($user_guidlookup) . "%27";
        } else {
            $apiurlfiltered = $apiurl . "?%24orderby=COURSE_STATE_DATE,date_created+asc&%24filter=date_created+gt+%27" . urlencode($from) . "%27+and+date_created+lt+%27" . urlencode($to) . '%27';
        }

        // Make API call.
        $options = array(
            'RETURNTRANSFER' => 1,
            'HEADER' => 0,
            'FAILONERROR' => 1,
        );
        $header = array('x-cdata-authtoken: ' . $apitoken);
        $curl = new curl();
        $curl->setHeader($header);
        $response = $curl->get($apiurlfiltered, $options);
        
        // Check for cURL errors
        if ($curl->get_errno()) {
            echo '<div class="alert alert-danger">Error: Unable to fetch data from API.</div>';
        } else {

            $data = json_decode($response, true); // Decode the JSON response

        }
    }
}

// Display the feedback if there is any
if (!empty($feedback)) {
    echo '<div class="alert alert-info">' . $feedback . '</div>';
}

// Helper function to check if the user is enrolled in the course
function check_user_enrolment_status($courseidnumber, $userid) {
    global $DB;

    // Find the course by idnumber
    $course = $DB->get_record('course', ['idnumber' => $courseidnumber]);
    if (!$course) {
        return 'Course not found';
    }

    // Check if the user is enrolled in the course using enrol_get_users_courses()
    $user_courses = enrol_get_users_courses($userid, true, ['id']);
    
    // Iterate through courses the user is enrolled in
    foreach ($user_courses as $user_course) {
        if ($user_course->id == $course->id) {
            // User is enrolled in this course
            return true;
        }
    }
    return false;
}

// Helper function to create a new user
// #TODO this likely doesn't need to be here; should be able to use from lib.php
function create_new_user($user_email, $first_name, $last_name, $user_guid) {
    global $DB;

    $user = new stdClass();
    $user->username = strtolower($user_email);
    $user->email = $user_email;
    $user->idnumber = $user_guid;
    $user->firstname = $first_name;
    $user->lastname = $last_name;
    $user->password = hash_internal_user_password(random_string(8));
    $user->confirmed = 1; // Confirmed account
    $user->auth = 'oauth2';
    $user->emailformat = 1; // 1 for HTML, 0 for plain text
    $user->mnethostid = 1;
    $user->timecreated = time();
    $user->timemodified = time();

    $user->id = user_create_user($user, true, false);

    return $user;

}


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
        <a class="nav-link active" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/manual-complete.php">Manual Complete</a>
    </li>
</ul>
<!-- Form to input the 'from' and 'to' dates -->
<form method="post" action="<?php echo $PAGE->url; ?>" class="mb-3">
    <div class="row">
    <div class="form-group col-2">
        <label for="from"><?php echo get_string('from', 'local_psaelmsync'); ?></label>
        <input type="datetime-local" id="from" name="from" class="form-control" value="<?php echo s($from); ?>">
    </div>
    <div class="form-group col-2">
        <label for="to"><?php echo get_string('to', 'local_psaelmsync'); ?></label>
        <input type="datetime-local" id="to" name="to" class="form-control" value="<?php echo s($to); ?>">
    </div>
    <div class="form-group col-2">
        <label for="emaillookup"><?php echo get_string('email_cdata_lookup', 'local_psaelmsync'); ?></label>
        <input type="email" id="emaillookup" name="emaillookup" class="form-control" value="<?php echo s($user_emaillookup); ?>">
    </div>
    <div class="form-group col-2">
        <label for="guidlookup"><?php echo get_string('guid_cdata_lookup', 'local_psaelmsync'); ?></label>
        <input type="text" id="guidlookup" name="guidlookup" class="form-control" value="<?php echo s($user_guidlookup); ?>">
    </div>
    </div>
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <button type="submit" class="btn btn-primary"><?php echo get_string('submit', 'local_psaelmsync'); ?></button>
</form>
<?php if(!empty($apiurlfiltered)): ?>
<div>   
    Query: <input type="text" name="queryurl" class="form-field" value="<?= $apiurlfiltered ?>">
    <a href="<?= $apiurlfiltered ?>" class="btn btn-sm btn-secondary" target="_blank">Launch</a> (VPN required; IP whitelist required)
</div>
<?php endif ?>
<!-- Result section where the fetched records will be displayed -->
<?php
if (!empty($data)) {
    if (isset($data['value']) && count($data['value']) > 0) {
        // Display the results in a table        
        echo '<div class="row">';
        // Loop through the records and display them
        foreach ($data['value'] as $record) {
            $logs = [];
            $coursefullname = '<strong>Cannot find course.</strong>';
            $moodlecourseid = 0;
            $courseidnum = (int) $record['COURSE_IDENTIFIER'];
            
            // Fetch the course based on idnumber
            if ($course = $DB->get_record('course', array('idnumber' => $courseidnum), 'id, fullname')) {
                $coursefullname = $course->fullname;
                $moodlecourseid = $course->id;
            }

            echo '<div class="col-md-4">';
            echo '<div class="m-2 p-3 bg-light rounded-lg">';
            // Find the user by GUID
            $user = $DB->get_record('user', ['idnumber' => $record['GUID']]);
            
            $enrol_status = 'Not Enrolled';
            $user_status = "Doesn't exist";
            
            // Check if user exists
            if ($user) {
                // Check if the user is enrolled in the course
                if (check_user_enrolment_status($record['COURSE_IDENTIFIER'], $user->id)) {
                    $enrol_status = 'Enrolled';
                }
                $user_status = "User exists";
                $elmcourseid = $record['COURSE_IDENTIFIER'];
                
                // Get enrolment logs from local_psaelmsync_logs table
                $logs = $DB->get_records('local_psaelmsync_logs', 
                                            [
                                                'elm_course_id' => $elmcourseid, 
                                                'user_id' => $user->id
                                            ], 
                                            '', 
                                            'timestamp, action, user_guid, user_email');
            }
            // Display a process form if needed
            if (($record['COURSE_STATE'] == 'Enrol' && $enrol_status == 'Enrolled') || ($record['COURSE_STATE'] == 'Suspend' && $enrol_status == 'Not Enrolled')) {
                // Do nothing
            } else {
                if (!empty($moodlecourseid)) {
                    echo '<form class="float-right ml-4" method="post" action="' . $PAGE->url . '">';
                    echo '<input type="hidden" name="elm_course_id" value="' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '">';
                    echo '<input type="hidden" name="elm_enrolment_id" value="' . htmlspecialchars($record['ENROLMENT_ID']) . '">';
                    echo '<input type="hidden" name="record_date_created" value="' . htmlspecialchars($record['date_created']) . '">';
                    echo '<input type="hidden" name="course_state" value="' . htmlspecialchars($record['COURSE_STATE']) . '">';
                    echo '<input type="hidden" name="class_code" value="' . htmlspecialchars($record['COURSE_SHORTNAME']) . '">';
                    echo '<input type="hidden" name="guid" value="' . htmlspecialchars($record['GUID']) . '">';
                    echo '<input type="hidden" name="email" value="' . htmlspecialchars($record['EMAIL']) . '">';
                    echo '<input type="hidden" name="first_name" value="' . htmlspecialchars($record['FIRST_NAME']) . '">';
                    echo '<input type="hidden" name="last_name" value="' . htmlspecialchars($record['LAST_NAME']) . '">';
                    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
                    echo '<button type="submit" name="process" class="btn btn-primary">Process</button>';
                    echo '</form>';
                }
            }
            // Display course and user data
            echo '<div>DATE CREATED:<br> ' . htmlspecialchars($record['date_created']) . '</div>';
            echo '<div>COURSE STATE: <strong>' . htmlspecialchars($record['COURSE_STATE']) . '</strong></div>';
            if(!empty($moodlecourseid)) {
                echo '<div>Enrollment Status (Moodle): <a href="/user/index.php?id=' . $moodlecourseid . '">' . $enrol_status . '</a></div>'; 
            } else {
                echo '<div>Enrollment Status (Moodle): ' . $enrol_status . '</div>'; 
            }
            echo '<div>COURSE IDENTIFIER: <a href="/course/view.php?idnumber=' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '">' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '</a> - ' . $coursefullname . '</div>';
            echo '<div>COURSE SHORTNAME: ' . htmlspecialchars($record['COURSE_SHORTNAME']) . '</div>';
            echo '<div title="The datetime they clicked Enrol in ELM">COURSE_STATE_DATE: ' . htmlspecialchars($record['COURSE_STATE_DATE']) . '</div>';
            
            // If the user exists, compare the Moodle and external data for mismatches
            if ($user) {
                echo '<div>User Status (Moodle): <a href="/user/view.php?id=' . $user->id . '">' . $user_status . '</a></div>';
                echo '<div>FIRST NAME: ' . htmlspecialchars($record['FIRST_NAME']) . '</div>';
                echo '<div>LAST NAME: ' . htmlspecialchars($record['LAST_NAME']) . '</div>';
                echo '<div>EMAIL: (cdata) ' . htmlspecialchars($record['EMAIL']) . '</div>';
                echo '<div>EMAIL: (moodle) ' . htmlspecialchars($user->email) . '</div>';
                
                // Check for email mismatch
                if (strtolower($record['EMAIL']) != strtolower($user->email)) {
                    echo '<div class="alert alert-warning"><strong>Email mismatch!</strong></div>';
                }
                
                echo '<div>GUID: (cdata) ';
                echo '<a href="/local/psaelmsync/dashboard.php?search=' . htmlspecialchars($record['GUID']) . '">';
                echo htmlspecialchars($record['GUID']);
                echo '</a></div>';
                echo '<div>GUID: (moodle) ' . htmlspecialchars($user->idnumber) . '</div>';
                
                // Check for GUID mismatch
                if ($record['GUID'] != $user->idnumber) {
                    echo '<div class="alert alert-warning"><strong>GUID mismatch!</strong></div>';
                }
            } else {
                // If the user doesn't exist, just show external data (no Moodle comparisons)
                echo '<div>User Status: ' . $user_status . '</div>';
                echo '<div>FIRST NAME: ' . htmlspecialchars($record['FIRST_NAME']) . '</div>';
                echo '<div>LAST NAME: ' . htmlspecialchars($record['LAST_NAME']) . '</div>';
                echo '<div>EMAIL: (cdata) ' . htmlspecialchars($record['EMAIL']) . '</div>';
                echo '<div>GUID: (cdata) ';
                echo '<a href="/local/psaelmsync/dashboard.php?search=' . htmlspecialchars($record['GUID']) . '">';
                echo htmlspecialchars($record['GUID']);
                echo '</a></div>';
            }

            // Display existing logs if any
            if (!empty($logs)) {
                echo '<div class=""><strong>Existing logs for this course</strong></div>';
                foreach ($logs as $l) {
                    $iso8601 = date('Y-m-d H:i:s', (int) $l->timestamp);
                    echo '<div class="p-2 m-1 bg-white rounded-lg">';
                    echo '<a href="/local/psaelmsync/dashboard.php?search=' . urlencode($iso8601) . '">';
                    echo $iso8601 . ' - ' . $l->action . ' - ' . $l->user_email . ' - ' . $l->user_guid;
                    echo '</a></div>';
                }
            } else {
                echo '<div class="alert alert-secondary">No matching logs found.</div>';
            }

            

            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="alert alert-warning">No data found for the selected dates.</div>';
    }
}

echo $OUTPUT->footer();
