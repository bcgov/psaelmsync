<?php
defined('MOODLE_INTERNAL') || die();

function local_psaelmsync_sync() {
    global $DB;
    $runlogstarttime = floor(microtime(true) * 1000);

    // https://analytics-testapi.psa.gov.bc.ca/apiserver/api.rsc/Datamart_ELM_Course_Enrollment_info
    // Fetch API URL and token from config.
    $apiurl = get_config('local_psaelmsync', 'apiurl');
    $apitoken = get_config('local_psaelmsync', 'apitoken');
    $datefilter = get_config('local_psaelmsync', 'datefilterminutes');

    if (!$apiurl || !$apitoken) {
        mtrace('PSA Enrol Sync: API URL or Token not set.');
        return;
    }
    $mins = '-' . $datefilter . ' minutes';
    $time_minus_mins = date('Y-m-d H:i:s', strtotime($mins));
    // $apiurlfiltered = $apiurl . '&%24filter=date_created+gt+%27' . $time_minus_mins .'%27'; // 2024-08-06T11%3A00%27
    $apiurlfiltered = $apiurl . ''; // until we're actually dealing with cdata and not the mock endpoint

    // Make API call.
    $curl = new curl();
    $options = array(
        'CURLOPT_HTTPHEADER' => array("Authorization: Bearer $apitoken"),
    );
    $response = $curl->get($apiurlfiltered, $options);

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
    // How performant is this? Can we not just rely on the URL filter being
    // sorted in the right order? Or do we need this overhead?
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
        $action = process_enrolment_record($record,$apiurlfiltered);
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

function process_enrolment_record($record, $apiurl) {
    
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
    $record_id = floor(microtime(true) * 1000); // (int) $record['record_id'];
    // In current state the plan is to use the USER_STATE field to hold the 
    // enrolment ID. At some point hopefully we'll get away from the spaghetti 
    // that is our field mapping.
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
    
    // Not only a decent idea, but also until we can get actual unique IDs in cdata,
    // we basically need to create a unique ID here by hashing the relevent info.
    // Is this computationally expensive? 
    // We'll want to include $enrolment_id in this hash for extra-good unqiueness but 
    // right now we're dynamically generating them (should we just not maybe?) which 
    // would break this, so just leaving it out for the time being. I think the date_created
    // field does a good enough job for now.
    $hash_content = $record_date_created . $course_id . $class_code . $enrolment_status . $user_guid . $user_email;
    $hash = hash('sha256', $hash_content);

    $hashcheck = $DB->get_record('local_psaelmsync_enrol', ['sha256hash' => $hash], '*', IGNORE_MULTIPLE);

    // Does the hash exist in the table? If so we want to skip this record as 
    // we've already processed it.
    if ($hashcheck) {
        return;
    }

    // If there's no course with this IDNumber (note: not the Moodle course ID 
    // but ELM's course ID), skip record. We want to log that this is happening 
    // somehow; should probably send an email #TODO
    if (!$course = $DB->get_record('course', array('idnumber' => $course_id))) {
        log_record($record_id, $apiurl, $hash, $record_date_created, $course_id, $class_code, $enrolment_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'error', 'Course not found');
        return;
    }

    // Check if user exists by GUID or email.
    if ($user = $DB->get_record('user', array('idnumber' => $user_guid),'*')) {
        $user_id = $user->id;
    } else {
        // Create new user.
        $user = create_user($user_first_name, $user_last_name, $user_email, $user_guid);
        $user_id = $user->id;
    }

    if ($enrolment_status == 'Enrol') {
        // Enrol the user in the course.
        enrol_user_in_course($user_id, $course->id, $enrolment_id, $hash, $record_id, $class_code);

        send_welcome_email($user, $course);

        log_record($record_id, $apiurl, $hash, $record_date_created, $course->id, $class_code, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'enrolled', 'Success');

    } elseif ($enrolment_status == 'Suspend') {
        // Suspend the user in the course.
        suspend_user_in_course($user_id, $course->id);
        log_record($record_id, $apiurl, $hash, $record_date_created, $course->id, $class_code, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, 0, 'suspended', 'Success');
    }

    // Callback API with processed status.
    // DISABLED for now as we're now checking for hashes, but this still might be a good approach.
    // update_api_processed_status($record_id);
    
    // We return the enrolment_status so that we can count enrols and suspends
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

function enrol_user_in_course($user_id, $course_id, $enrolment_id, $hash, $record_id, $class_code) {
    global $DB;

    $enrol = enrol_get_plugin('manual');
    if ($enrol) {
        
        $instance = $DB->get_record('enrol', array('courseid' => $course_id, 'enrol' => 'manual'), '*', MUST_EXIST);
        $enrol->enrol_user($instance, $user_id, $instance->roleid);

        // Store the custom enrolment ID in the new table.
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
    $message = "Dear {$user->firstname} {$user->lastname},\n\nYou have been enrolled in the course '{$course->fullname}'.\n\nBest regards,\nMoodle Team";

    email_to_user($user, core_user::get_support_user(), $subject, $message);
}

function update_api_processed_status($record_id) {
    global $DB;
    
    $apiupdateurl = get_config('local_psaelmsync', 'apiupdateurl');
    $apiupdatetoken = get_config('local_psaelmsync', 'apiupdatetoken');
    $callbackurl = $apiupdateurl . $record_id;
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
            "Authorization: Bearer " . $apiupdatetoken,
            "Content-Type: application/json",
            "Content-Length: " . strlen($jsonData)
        )
    );
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);
}

function log_record($record_id, $apiurl, $hash, $record_date_created, $course_id, $enrolment_id, $user_id, $user_first_name, $user_last_name, $user_email, $user_guid, $action, $status) {
    global $DB;

    $course = $DB->get_record('course', array('id' => $course_id), 'fullname', MUST_EXIST);

    $log = new stdClass();
    $log->record_id = $record_id;
    $log->apiurl = $apiurl;
    $log->sha256hash = $hash;
    $log->record_date_created = $record_date_created;
    $log->course_id = $course_id;
    $log->class_code =  $class_code;
    $log->course_name = $course->fullname;
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
