<?php
defined('MOODLE_INTERNAL') || die();

function local_psaelmsync_sync() {

    global $DB;

    $runlogstarttime = floor(microtime(true) * 1000);

    // Fetch API URL and token from config.
    $apiurl = get_config('local_psaelmsync', 'apiurl');
    $apitoken = get_config('local_psaelmsync', 'apitoken');
    $datefilter = get_config('local_psaelmsync', 'datefilterminutes');
    $notificationhours = get_config('local_psaelmsync', 'notificationhours');

    if (!$apiurl || !$apitoken) {
        mtrace('PSA Enrol Sync: API URL or Token not set.');
        return;
    }
    // Setup API Url with a date filter that only pulls in the records from N minutes ago
    $mins = '-' . $datefilter . ' minutes';
    $time_minus_mins = date('Y-m-d H:i:s', strtotime($mins));
    $encoded_time = urlencode($time_minus_mins);
    $apiurlfiltered = $apiurl . '&%24filter=date_created+gt+%27' . $encoded_time .'%27';

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

    if ($curl->get_errno()) {
        mtrace('PSA Enrol Sync: API request failed: ' . $apiurlfiltered);
        return;
    }

    $data = json_decode($response, true);

    if (empty($data)) {
        mtrace('PSA Enrol Sync: No data received from API: ' . print_r($response, true));
        return;
    }

    // Ensure that we're sorting the data by date_created in ascending order.
    // This isn't strictly necessary as the API call is also sorting this way,
    // but it is important that we process them in chronological order so we 
    // double-do it here. Removing this would be an easy optimization if that
    // becomes necessary.
    usort($data['value'], function ($a, $b) {
        return strtotime($a['date_created']) - strtotime($b['date_created']);
    });

    // Set up variables for type count logging.
    $typecounts = [];
    $recordcount = 0;
    $enrolcount = 0;
    $suspendcount = 0;
    $errorcount = 0;
    $skippedcount = 0;
    
    // This is the primary loop where we start to look at each record
    foreach ($data['value'] as $record) {
        $recordcount++;
        // Process each record. Returns the enrolment_type for logging
        $action = process_enrolment_record($record);
        $typecounts[] = $action;
    }

    // Loop through to pull out how many enrols and drops etc. respectively.
    foreach($typecounts as $t) {
        if($t == 'Enrol') $enrolcount++;
        if($t == 'Suspend') $suspendcount++;
        if($t == 'Error') $errorcount++;
        if($t == 'Skipped') $skippedcount++;
    }
    // Log the end of the run time.
    $runlogendtime = floor(microtime(true) * 1000);
    $log = [
            'apiurl' => $apiurlfiltered, 
            'starttime' => $runlogstarttime, 
            'endtime' => $runlogendtime, 
            'recordcount' => $recordcount,
            'enrolcount' => $enrolcount,
            'suspendcount' => $suspendcount,
            'errorcount' => $errorcount,
            'skippedcount' => $skippedcount
        ];
    $DB->insert_record('local_psaelmsync_runs', (object)$log);

    // Check for the time since the last enrolment or suspend. 
    // If it's been more than N hours then send an email to the 
    // admin list notifying them that the bridge might be blocked
    // on the ELM side.
    check_last_enrolment_or_suspend($notificationhours);
    

}

function process_enrolment_record($record) {
    
    global $DB;
    /**
     * Sample record 
     * {
     *   "COURSE_IDENTIFIER": 32090,
     *   "COURSE_STATE": "Suspend",
     *   "COURSE_STATE_DATE": "2023-04-11T08:06:43.000-07:00",
     *   "GUID": "0000DE7307A24F53B8DC558936D0000",
     *   "COURSE_SHORTNAME": "ITEM-2162-1",
     *   "date_created": "2024-08-14T11:10:20.000-07:00",
     *   "date_deleted": null,
     *   "date_updated": null,
     *   "EMAIL": "fizzbuzz@gov.bc.ca",
     *   "FIRST_NAME": "Fizz",
     *   "LAST_NAME": "Buzz",
     *   "USER_EFFECTIVE_DATE": "2023-06-09",
     *   "USER_STATE": "ACTIVE" //might be repurposed into enrolment_id holder
     * },
     */
    
    // CData doesn't currently supply a unique record ID yet, so we just make one up.
    // In milliseconds.
    $record_id = floor(microtime(true) * 1000); // (int) $record['record_id'];
    // In current state the plan is to use the USER_STATE field to hold the 
    // enrolment ID. At some point hopefully we'll get away from the spaghetti 
    // that is our field mapping. In the meantime, we're just faking an ID.
    $enrolment_id = floor(microtime(true) * 1000);// $record['USER_STATE'];
    // The rest map to CData fields
    $record_date_created = $record['date_created'];
    $course_id = (int) $record['COURSE_IDENTIFIER'];
    $enrolment_status = $record['COURSE_STATE'];
    $class_code = $record['COURSE_SHORTNAME'];
    $user_first_name = $record['FIRST_NAME'];
    $user_last_name = $record['LAST_NAME'];
    $user_email = $record['EMAIL'];
    $user_guid = $record['GUID'];
    
    // We need to create a unique ID here by hashing the relevent info.
    // When we have access to them, we'll want to include $enrolment_id and 
    // record_id in this hash for extra-good unqiueness but right now we're 
    // dynamically generating them (should we just not maybe?) which 
    // would break this(?), so just leaving it out for the time being. I think the 
    // data included does a good enough job for now. Two identical records coming 
    // through is enough of an edge case and wouldn't really have an adverse
    // effect anyhow.
    $hash_content = $record_date_created . $course_id . $class_code . $enrolment_status . $user_guid . $user_email;
    $hash = hash('sha256', $hash_content);
    // This is expensive part of doing it this way where we touch the database  
    // for every single record in the feed, but it's probably the least expensive 
    // but verifyable method that we can come up with; certainly less expensive
    // than updating each record with a callback.
    $hashcheck = $DB->get_record('local_psaelmsync_logs', ['sha256hash' => $hash], '*', IGNORE_MULTIPLE);

    // Does the hash exist in the table? If so we want to skip this record as 
    // we've already processed it, but still counting it as we go.
    if ($hashcheck) {
        $s = 'Skipped';
        return $s;
    }

    // If there's no course with this IDNumber (note: not the Moodle course ID 
    // but ELM's course ID), skip record. We want to log that this is happening 
    // somehow; should probably send an email #TODO
    if (!$course = $DB->get_record('course', array('idnumber' => $course_id))) {
        // We haven't done a user lookup yet so 
        $user_id = 0;
        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course_id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $user_first_name, 
                    $user_last_name, 
                    $user_email, 
                    $user_guid, 
                    'Course not found',
                    'error');
        
        return;
    }

    // Check if user exists by GUID     .
    if ($user = $DB->get_record('user', array('idnumber' => $user_guid),'*')) {

        $user_id = $user->id;

    } else {
        // Attempt to create a new user, handle any exceptions gracefully.
        try {

            $user = create_user($user_first_name, $user_last_name, $user_email, $user_guid);
            $user_id = $user->id;

        } catch (Exception $e) {

            // #TODO do an additional lookup at this point to see if the provided email 
            // exists and if it does send that account info along as well as the issue
            // is likely a GUID change.

            // Log the error
            log_record($record_id, 
                        $hash, 
                        $record_date_created, 
                        $course_id, 
                        $class_code, 
                        $enrolment_id, 
                        0, // No user ID since creation failed
                        $user_first_name, 
                        $user_last_name, 
                        $user_email, 
                        $user_guid, 
                        'User creation failed',
                        'error');
            
            // Send an email notification
            send_failure_notification($user_first_name, $user_last_name, $user_email, $e->getMessage());

            // Return to skip further processing of this record.
            return;
        }
    }

    if ($enrolment_status == 'Enrol') {
        // Enrol the user in the course.
        enrol_user_in_course($user_id, $course->id, $enrolment_id, $hash, $record_id, $class_code);

        send_welcome_email($user, $course);

        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course->id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $user_first_name, 
                    $user_last_name, 
                    $user_email, 
                    $user_guid, 
                    'enrolled', 
                    'Success');

    } elseif ($enrolment_status == 'Suspend') {
        // Suspend the user in the course.
        suspend_user_in_course($user_id, $course->id);

        // #TODO send a drop email?? ya!

        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course->id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $user_first_name, 
                    $user_last_name, 
                    $user_email, 
                    $user_guid, 
                    'suspended', 
                    'Success');
    }
    
    // We return the enrolment_status so that we can count enrols and suspends
    // when we log the run.
    return $enrolment_status;

}

function create_user($first_name, $last_name, $email, $guid) {
    global $DB;

    $user = new stdClass();
    $user->auth = 'manual';
    $uname = strtolower($email);
    $user->username = $uname;
    $user->mnethostid = 1;
    $user->password = hash_internal_user_password('Chang3m3Please!');
    $user->firstname = $first_name;
    $user->lastname = $last_name;
    $user->email = $email;
    $user->idnumber = $guid;
    $user->confirmed = 1;
    $user->timecreated = time();
    $user->timemodified = time();

    $user_id = $DB->insert_record('user', $user);
    $user->id = $user_id;

    return $user;
}

function enrol_user_in_course($user_id, $course_id, $enrolment_id, $hash, $record_id, $class_code) {
    global $DB;

    $enrol = enrol_get_plugin('manual');
    if ($enrol) {
        
        $instance = $DB->get_record('enrol', array('courseid' => $course_id, 'enrol' => 'manual'), '*', MUST_EXIST);
        $enrol->enrol_user($instance, $user_id, $instance->roleid);

        // Store the custom enrolment ID in the enrol table.
        $custom_enrolment = new stdClass();
        $custom_enrolment->userid = $user_id;
        $custom_enrolment->record_id = $record_id;
        $custom_enrolment->sha256hash = $hash;
        $custom_enrolment->enrolid = $instance->id;
        $custom_enrolment->elm_enrolment_id = $enrolment_id;
        $custom_enrolment->course_id = $course_id;
        $custom_enrolment->class_code =  $class_code;
        $custom_enrolment->timecreated = time();
        $DB->insert_record('local_psaelmsync_enrol', $custom_enrolment);

    }
}

function suspend_user_in_course($user_id, $course_id) {
    global $DB;

    $enrol = enrol_get_plugin('manual');
    if ($enrol) {
        $instance = $DB->get_record('enrol', array('courseid' => $course_id, 'enrol' => 'manual'), '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $user_id);
        #TODO should we be also deleting the record from local_psaelmsync_enrol here?
    }

}

function send_welcome_email($user, $course) {
    $subject = "Welcome to {$course->fullname}";
    // $message = "Dear {$user->firstname} {$user->lastname},\n\nYou have been enrolled in the course '{$course->fullname}'.\n\n'{$course->id}'.\n\nBest regards,\nPSA Learning Centre";
    $message = <<<EMAIL
            Hello $user->firstname $user->lastname,\n\n
            You have been enrolled in the course "$course->fullname".\n\n
            https://learning.gww.gov.bc.ca/course/view.php?id=$course->id \n\n
            Best regards,\n
            PSA Learning Centre
    EMAIL;

    email_to_user($user, core_user::get_support_user(), $subject, $message);
}

function log_record($record_id, $hash, $record_date_created, $course_id, $class_code, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, $action, $status) {
    global $DB;

    // Ensure course_id is valid before lookup
    $coursefullname = 'Not found!';
    if (!empty($course_id) && is_numeric($course_id)) {
        if ($course = $DB->get_record('course', array('id' => $course_id), 'fullname')) {
            $coursefullname = $course->fullname;
        }
    }

    $log = new stdClass();
    $log->record_id = $record_id;
    $log->sha256hash = $hash;
    $log->record_date_created = $record_date_created;
    $log->course_id = $course_id;
    $log->class_code = $class_code;
    $log->course_name = $coursefullname;
    $log->user_id = $user_id;
    $log->user_firstname = $user_first_name;
    $log->user_lastname = $user_last_name;
    $log->user_guid = $user_guid; 
    $log->user_email = $user_email;
    $log->elm_enrolment_id = $enrolment_id;
    $log->action = $action;
    $log->status = $status;
    $log->timestamp = time();

    $DB->insert_record('local_psaelmsync_logs', $log);
}

// Function to send failure notification email
function send_failure_notification($first_name, $last_name, $email, $error_message) {
    global $CFG;

    // Get the list of email addresses from admin settings
    $admin_emails = get_config('local_psaelmsync', 'notificationemails');
    
    if (!empty($admin_emails)) {
        $emails = explode(',', $admin_emails);
        $subject = "User Creation Failure Notification";
        $message = "A failure occurred during user creation.\n\n";
        $message .= "Details:\n";
        $message .= "Name: {$first_name} {$last_name}\n";
        $message .= "Email: {$email}\n";
        $message .= "Error: {$error_message}\n\n";
        $message .= "Please investigate the issue.";
        
        // Create a dummy user object for sending the email
        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99; // Dummy user id
        
        foreach ($emails as $admin_email) {
            // Trim to remove any extra whitespace around email addresses
            $admin_email = trim($admin_email);
            
            // Create a recipient user object
            $recipient = new stdClass();
            $recipient->email = $admin_email;
            $recipient->id = -99; // Dummy user id
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';
            
            // Send the email
            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}

function check_last_enrolment_or_suspend($notificationhours) {
    global $DB;

    // Calculate the threshold time.
    $threshold_time = time() - ($notificationhours * 3600);

    // Check if the current time is between 6 AM and 6 PM on a weekday.
    $current_time = time();
    $day_of_week = date('N', $current_time); // 1 (for Monday) through 7 (for Sunday)
    $hour_of_day = date('G', $current_time); // 0 through 23

    if ($day_of_week >= 6 || $hour_of_day < 6 || $hour_of_day >= 18) {
        // Do not send notifications outside of 6 AM - 6 PM, Monday to Friday.
        return;
    }

    // Query the last enrolment or suspend record.
    $last_action = $DB->get_record_sql("
        SELECT MAX(timestamp) AS lasttime
        FROM {local_psaelmsync_logs}
        WHERE action IN ('enrolled', 'suspended')
    ");

    if ($last_action && $last_action->lasttime < $threshold_time) {
        // If the last action was before the threshold, send a notification.
        send_inactivity_notification($notificationhours);

    }
}

function send_inactivity_notification($notificationhours) {
    global $CFG;

    $admin_emails = get_config('local_psaelmsync', 'notificationemails');
    
    if (!empty($admin_emails)) {
        $emails = explode(',', $admin_emails);
        $subject = "PSA Enrol Sync Inactivity Notification";
        $message = "No enrolment or suspension records have been processed in the last {$notificationhours} hours. Please check the system.";

        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99;

        foreach ($emails as $admin_email) {
            $admin_email = trim($admin_email);

            $recipient = new stdClass();
            $recipient->email = $admin_email;
            $recipient->id = -99;
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';

            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}