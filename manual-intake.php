<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php'); // Include lib.php
require_login();

global $DB, $PAGE, $OUTPUT;

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
        // Processing a single record
        $record_date_created = required_param('record_date_created', PARAM_TEXT);
        $course_identifier = required_param('course_identifier', PARAM_TEXT);
        $course_state = required_param('course_state', PARAM_TEXT);
        $guid = required_param('guid', PARAM_TEXT);
        $class_code = required_param('class_code', PARAM_TEXT);
        $email = required_param('email', PARAM_TEXT);
        $first_name = required_param('first_name', PARAM_TEXT);
        $last_name = required_param('last_name', PARAM_TEXT);

        $hash_content = $record_date_created . $course_identifier . $class_code . $course_state . $user_guid . $user_email;
        $hash = hash('sha256', $hash_content);
        
        // Find the course by its idnumber (COURSE_IDENTIFIER maps to idnumber)
        $course = $DB->get_record('course', ['idnumber' => $course_identifier]);
        if ($course) {
            // Find the user by email, create if they don't exist
            $user = $DB->get_record('user', ['email' => $email]);
            if (!$user) {
                // Create the new user if they don't exist
                $user = create_new_user($email, $first_name, $last_name);
                if (!$user) {
                    $feedback = "Failed to create a new user for email {$email}.";
                }
            }

            if ($user) {
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
                        $manual_enrol->enrol_user($manual_instance, $user->id, $manual_instance->roleid, time());
                        // Check if enrolment was successful
                        $is_enrolled = $DB->record_exists('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual_instance->id]);
                                        
                        if ($is_enrolled) {
                            // Log it!!
                            $log = new stdClass();
                            $log->record_id = time();
                            $log->sha256hash = $hash;
                            $log->record_date_created = $record_date_created;
                            $log->course_id = $course->id;
                            $log->elm_course_id = $course_identifier;
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

                        // Log it!!
                        $log = new stdClass();
                        $log->record_id = time();
                        $log->sha256hash = $hash;
                        $log->record_date_created = $record_date_created;
                        $log->course_id = $course->id;
                        $log->elm_course_id = $course_identifier;
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
            $feedback = "Course with identifier {$course_identifier} not found.";
        }
    } else {
        // Handle the date range form submission (API query)

        // Build the API URL with date filters
        $from = required_param('from', PARAM_TEXT);
        $to = required_param('to', PARAM_TEXT);
        $emaillookup = required_param('emaillookup', PARAM_TEXT);
        if(!empty($emaillookup)) {
            // $apiurlfiltered = $apiurl . "&filter=date_created,gt," . urlencode($from) . "&filter=date_created,lt," . urlencode($to); // MOCK API format
            $apiurlfiltered = $apiurl . "&%24filter=email+eq+%27" . urlencode($emaillookup) . "%27";
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
function create_new_user($email, $first_name, $last_name) {
    global $DB;

    $user = new stdClass();
    $user->username = strtolower($email);
    $user->email = $email;
    $user->firstname = $first_name;
    $user->lastname = $last_name;
    $user->password = hash_internal_user_password(generate_password());
    $user->confirmed = 1; // Confirmed account
    $user->auth = 'manual'; // Manual authentication

    // Insert the new user into the database
    return $DB->insert_record('user', $user) ? $DB->get_record('user', ['email' => $email]) : false;
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
        <input type="email" id="emaillookup" name="emaillookup" class="form-control" value="<?php echo s($emaillookup); ?>">
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
                echo '<input type="hidden" name="course_identifier" value="' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '">';
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
