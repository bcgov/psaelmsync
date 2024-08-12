<?php
defined('MOODLE_INTERNAL') || die();

function local_psaelmsync_sync() {
    global $DB;
    $runlogstarttime = floor(microtime(true) * 1000);

    // Fetch API URL and token from config.
    $apiurl = get_config('local_psaelmsync', 'apiurl');
    $apitoken = get_config('local_psaelmsync', 'apitoken');

    if (!$apiurl || !$apitoken) {
        mtrace('PSA Enrol Sync: API URL or Token not set.');
        return;
    }

    // Make API call.
    $curl = new curl();
    $options = array(
        'CURLOPT_HTTPHEADER' => array("Authorization: Bearer $apitoken"),
    );
    $response = $curl->get($apiurl, $options);

    if ($curl->get_errno()) {
        mtrace('PSA Enrol Sync: API request failed: ' . $curl->error);
        return;
    }

    $data = json_decode($response, true);

    if (empty($data)) {
        mtrace('PSA Enrol Sync: No data received from API.');
        return;
    }

    // Ensure that we're sorting the data by date_created in ascending order.
    usort($data['records'], function ($a, $b) {
        return strtotime($a['date_created']) - strtotime($b['date_created']);
    });
    $typecounts = [];
    $recordcount = 0;
    $enrolcount = 0;
    $suspendcount = 0;
    foreach ($data['records'] as $record) {
        $recordcount++;
        // Process each record. Returns the enrolment_type for logging
        $action = process_enrolment_record($record);
        $typecounts[] = $action;
    }
    // Loop through to pull out how many enrols and drops respectively.
    foreach($typecounts as $t) {
        if($t == 'Enrol') $enrolcount++;
        if($t == 'Suspend') $suspendcount++;
    }
    // Log the end of the run time.
    $runlogendtime = floor(microtime(true) * 1000);
    $log = [
            'starttime' => $runlogstarttime, 
            'endtime' => $runlogendtime, 
            'recordcount' => $recordcount,
            'enrolcount' => $enrolcount,
            'suspendcount' => $suspendcount
        ];
    $DB->insert_record('local_psaelmsync_runs', (object)$log);
}

function process_enrolment_record($record) {
    
    global $DB;
    /**
     * Sample record 
     * record_id	29412
     * processed	0
     * ernol_status	"Enrol"
     * ernolment_id	31254
     * course_id	29916
     * class_code	"ITEM-2089-1"
     * first_name	"Fizz"
     * last_name	"Buzz"
     * email	"Fizz.Buzz@gov.bc.ca"
     * GUID	"06338216D944CAAA041DCED300FDC"
     * date_created	"2024-07-25 13:00:20"
     * date_deleted	null
     * date_updated	null
     */
    
    $record_id = $record['record_id'];
    $record_date_created = $record['date_created'];
    $course_id = $record['course_id'];
    $enrolment_status = $record['ernol_status'];
    $enrolment_id = $record['ernolment_id'];
    $user_first_name = $record['first_name'];
    $user_last_name = $record['last_name'];
    $user_email = $record['email'];
    $user_guid = $record['GUID'];

    // Get course by IDNumber.
    if (!$course = $DB->get_record('course', array('idnumber' => $course_id))) {
        log_record($record_id, $record_date_created, $course_id, $enrolment_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'error', 'Course not found');
        return;
    }

    // Check if user exists by GUID or email.
    if ($user = $DB->get_record('user', array('idnumber' => $user_guid))) {
        $user_id = $user->id;
    } else {
        // Create new user.
        $user = create_user($user_first_name, $user_last_name, $user_email, $user_guid);
        $user_id = $user->id;
    }

    if ($enrolment_status == 'Enrol') {
        // Enrol the user in the course.
        enrol_user_in_course($user_id, $course->id, $enrolment_id);
        //send_welcome_email($user, $course);
        log_record($record_id, $record_date_created, $course->id, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'enrolled', 'Success');
    } elseif ($enrolment_status == 'Suspend') {
        // Suspend the user in the course.
        suspend_user_in_course($user_id, $course->id);
        log_record($record_id, $record_date_created, $course->id, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'suspended', 'Success');
    }

    // Callback API with processed status.
    update_api_processed_status($record_id);
    
    // We return the enrolment_status so that we can count each enrol or suspend
    // when we log the run.
    return $enrolment_status;

}

function create_user($first_name, $last_name, $email, $guid) {
    global $DB;

    $user = new stdClass();
    $user->auth = 'manual';
    $uname = strtolower($first_name.'.'.$last_name);
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

function enrol_user_in_course($user_id, $course_id, $enrolment_id) {
    global $DB;

    $enrol = enrol_get_plugin('manual');
    if ($enrol) {
        
        $instance = $DB->get_record('enrol', array('courseid' => $course_id, 'enrol' => 'manual'), '*', MUST_EXIST);
        $enrol->enrol_user($instance, $user_id, $instance->roleid);

        // Store the custom enrolment ID in the new table.
        $custom_enrolment = new stdClass();
        $custom_enrolment->userid = $user_id;
        $custom_enrolment->enrolid = $instance->id;
        $custom_enrolment->elm_enrolment_id = $enrolment_id;
        $custom_enrolment->course_id = $course_id;
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
    }
}

function send_welcome_email($user, $course) {
    $subject = "Welcome to {$course->fullname}";
    $message = "Dear {$user->firstname} {$user->lastname},\n\nYou have been enrolled in the course '{$course->fullname}'.\n\nBest regards,\nMoodle Team";

    // email_to_user($user, core_user::get_support_user(), $subject, $message);
    email_to_user('allankh@icloud.com', core_user::get_support_user(), $subject, $message);
}

function update_api_processed_status($record_id) {
    global $DB;

    $callbackurl = 'https://cdata.virtuallearn.ca/api.php/records/enrol/' . $record_id;
    $ch = curl_init($callbackurl);
    $data = array(
        'processed' => 1
    );
    $jsonData = json_encode($data);
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer fnjnejjnjnj",
            "Content-Type: application/json",
            "Content-Length: " . strlen($jsonData)
        )
    );
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);
}

function log_record($record_id, $record_date_created, $course_id, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, $action, $status) {
    global $DB;

    $course = $DB->get_record('course', array('id' => $course_id), 'fullname', MUST_EXIST);

    $log = new stdClass();
    $log->record_id = $record_id;
    $log->record_date_created = $record_date_created;
    $log->course_id = $course_id;
    $log->course_name = $course->fullname;
    $log->user_id = $user_id;
    $log->user_firstname = $user_first_name;
    $log->user_lastname = $user_last_name;
    $log->user_guid = $user_guid; 
    $log->user_email = $user_email;
    $log->enrolment_id = $enrolment_id;
    $log->action = $action;
    $log->status = $status;
    $log->timestamp = time();

    $DB->insert_record('local_psaelmsync_logs', $log);
}
