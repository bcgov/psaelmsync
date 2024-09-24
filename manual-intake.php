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
        require_sesskey();
        // Processing a single record
        $record_date_created = required_param('record_date_created', PARAM_TEXT);
        $course_state = required_param('course_state', PARAM_TEXT);
        $elm_course_id = required_param('elm_course_id', PARAM_TEXT);
        $user_guid = required_param('guid', PARAM_TEXT);
        $class_code = required_param('class_code', PARAM_TEXT);
        $user_email = required_param('email', PARAM_TEXT);
        $first_name = required_param('first_name', PARAM_TEXT);
        $last_name = required_param('last_name', PARAM_TEXT);

        $hash_content = $record_date_created . $elm_course_id . $class_code . $course_state . $user_guid . $user_email;
        $hash = hash('sha256', $hash_content);
        
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
                // to see if the email address associated with the account is consistent.
                $user_emailmismatch = 0; // for detecting and updating log action
                if ($user->email != $user_email) {
            
                    // Check if another user already has the new email address
                    $useremailcheck = $DB->get_record('user', ['email' => $user_email]);
            
                    // We base usernames on email addresses, but we want to be double-sure
                    // and so also want to check to ensure that the username doesn't exist. 
                    // These are almost certainly going to be the same, but weird things happen 
                    // around here so we just make sure.
                    // Generate the new username based on the new email address for comparison
                    $new_username = strtolower($user_email);
                    // If we need to optimize this process at any point, this lookup might 
                    // be considered a bit redundant.
                    $username_exists = $DB->record_exists('user', ['username' => $new_username]);
            
                    // We're going to send an email either way, but the message will vary.
                    $message = 'We\'ve come across a learner with an account with the given GUID, but ';
                    $message .= 'when we lookup the provided email address, it doesn\'t match and ';
            
                    if (!$useremailcheck && !$username_exists) {
            
                        // There isn't an existing account with this email or username.
                        // Assume that the new email address if the correct one and 
                        // update the account accordingly.
                        // #TODO review this to see if it's the right thing to do.
                        $user->email = validate_email($user_email) ? $user_email : null;
                        $user->username = core_user::clean_field($new_username, 'username');
                        $user->timemodified = time();
                        // Update the user record in the database
                        user_update_user($user, true, false);
            
                        $message .= 'there is no other account by that username/email address,';
                        $message .= 'so we have updated the user\'s account accordingly.\n';
                        $message .= 'CData name/GUID ' . $first_name . ' ' . $last_name . ': ' . $user_guid . '\n';
                        $message .= 'Existing email: <' . $user->email . '>' . '\n';
                        $message .= 'Email from CData record: <' . $user_email . '>';
            
                        // We're updating and moving on, but later on when we log this record, 
                        // we'll want to indicate that this was record was "Updated and Enrolled"
                        $user_emailmismatch = 1;
                        // Send the email notification
                        // NOTE: send_failure_notification is a misnomer here as we're not failing
                        // but we want to send a notification anyhow and don't need another function
                        // for it.
                        send_failure_notification('emailmismatch', $first_name, $last_name, $user_email, $message);
            
                        // Do nothing else here and the script moves on...
            
                    } else { // BUT if there IS another account with this email or username
                        
                        // Should we do further checks here to see if the other account 
                        // has ever been logged into? Is it enrolled in anything else?
                        // If it's a blank account maybe make the decision to delete it
                        // and update this one as we do if there's no account at all.
                        // 
                        if ($useremailcheck) {
                            $message .= 'there is <a href="/user/view.php?id=' . $useremailcheck->id . '">';
                            $message .= 'another account</a> with this email address.\n';
                        }
                        // As we base our usernames on email address, this lookup will almost
                        // always return the exact same user. If the username exists AND it's 
                        // not the same account then add addtional context.
                        if ($username_exists && $useremailcheck->id != $username_exists->id) {
                            $message .= 'As well, the username derived from the new email ';
                            $message .= '<a href="/user/view.php?id=' . $username_exists->id . '">is already in use</a>.\n';
                        }
                        $message .= 'Please investigate further.';
            
                        // Given that there's a conflict here that needs to be resolved by
                        // a human, we error out at this point, logging it and sending the notification.
                        // Log the error
                        $record_id = floor(microtime(true) * 1000); // make one up until we get a real field
                        $enrolment_id = floor(microtime(true) * 1000); // ditto

                        log_record($record_id,
                                    $hash, 
                                    $record_date_created, 
                                    $course->id, 
                                    $elm_course_id,
                                    $class_code, 
                                    $enrolment_id,
                                    $user->id,
                                    $first_name, 
                                    $last_name, 
                                    $user_email, 
                                    $user_guid, 
                                    'Email Mistatch',
                                    'Error');
                        
                        // Send the email notification
                        send_failure_notification('emailmismatch', $first_name, $last_name, $user_email, $message);
            
                        $e = 'Error';
                        return $e;
                    }
                }

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
                            $log->elm_enrolment_id = time();
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
                        $log->elm_enrolment_id = time();
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
        if(!empty($user_emaillookup)) {
            // $apiurlfiltered = $apiurl . "&filter=date_created,gt," . urlencode($from) . "&filter=date_created,lt," . urlencode($to); // MOCK API format
            $apiurlfiltered = $apiurl . "&%24filter=email+eq+%27" . urlencode($user_emaillookup) . "%27";
        } elseif(!empty($user_guidlookup)) {
            $apiurlfiltered = $apiurl . "&%24filter=GUID+eq+%27" . urlencode($user_guidlookup) . "%27";
        } else {
            $apiurlfiltered = $apiurl . "&%24filter=date_created+gt+%27" . urlencode($from) . "%27+and+date_created+lt+%27" . urlencode($to) . '%27';
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
    $user->auth = 'manual'; // Manual authentication
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

<!-- Result section where the fetched records will be displayed -->
<?php
if (!empty($data)) {
    if (isset($data['value']) && count($data['value']) > 0) {
        // Display the results in a table
        echo '<table class="table table-striped table-bordered">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>COURSE IDENTIFIER</th>';
        echo '<th>COURSE STATE</th>';
        echo '<th>GUID</th>';
        echo '<th>COURSE SHORTNAME</th>';
        echo '<th>DATE CREATED</th>';
        echo '<th>EMAIL</th>';
        echo '<th>FIRST NAME</th>';
        echo '<th>LAST NAME</th>';
        echo '<th>USER STATE (CData)</th>';
        echo '<th>User Status (Moodle)</th>'; // New column for enrollment status
        echo '<th>Enrollment Status</th>'; // New column for enrollment status
        echo '<th>Process</th>'; // New column for process button
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Loop through the records and display them
        foreach ($data['value'] as $record) {
            // Find the user by email
            $user = $DB->get_record('user', ['email' => $record['EMAIL']]);
            $enrol_status = 'Not Enrolled';
            $user_status = "Doesn't exist";
            if ($user) {
                // Check if the user is enrolled in the course
                if (check_user_enrolment_status($record['COURSE_IDENTIFIER'], $user->id)) {
                    $enrol_status = 'Enrolled';
                }
                $user_status = "User exists";
            } 

            echo '<tr>';
            echo '<td>' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '</td>';
            echo '<td>' . htmlspecialchars($record['COURSE_STATE']) . '</td>';
            echo '<td>' . htmlspecialchars($record['GUID']) . '</td>';
            echo '<td>' . htmlspecialchars($record['COURSE_SHORTNAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['date_created']) . '</td>';
            echo '<td>' . htmlspecialchars($record['EMAIL']) . '</td>';
            echo '<td>' . htmlspecialchars($record['FIRST_NAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['LAST_NAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['USER_STATE']) . '</td>';
            echo '<td>' . $user_status . '</td>'; // Show enrollment status
            echo '<td>' . $enrol_status . '</td>'; // Show enrollment status
            // Add a process button for each record
            echo '<td>';
            if($record['COURSE_STATE'] == 'Enrol' && $enrol_status == 'Enrolled' || $record['COURSE_STATE'] == 'Suspend' && $enrol_status == 'Not Enrolled') {

            } else {
                echo '<form method="post" action="' . $PAGE->url . '">';
                echo '<input type="hidden" name="elm_course_id" value="' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '">';
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
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<div class="alert alert-warning">No data found for the selected dates.</div>';
    }
}

echo $OUTPUT->footer();
