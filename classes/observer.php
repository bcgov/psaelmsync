<?php

namespace local_psaelmsync;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function course_completed(\core\event\course_completed $event) {

        global $DB;
        global $CFG;
        // Get course ID from event data.
        $courseid = $event->courseid;
        // Get user ID from event data.
        $userid = $event->relateduserid;
        // Get the course details so we can look up whether this course 
        // is opted in to sending its completion data across to CData
        // and get its ID Number 
        $course = get_course($courseid);

        $courseelement = new \core_course_list_element($course);

        if ($courseelement->has_custom_fields()) {
            
            $fields = $courseelement->get_custom_fields();
            // Iterate through the custom fields.
            foreach ($fields as $field) {
                // Get the shortname of the custom field.
                $shortname = $field->get_field()->get('shortname');
                // Check if this field is 'completion_opt_in'.
                if ($shortname === 'completion_opt_in') {
                    // Get the value of the custom field.
                    $value = $field->get_value();
                    // Check if the value indicates that the course is opted in.
                    if ($value == 1) { // Assuming 1 means the checkbox is checked.
            
                        // OK! this course is opted into sending the completion 
                        // back to ELM for processing.

                        // Get the course ID Number to match ELM's course_id 
                        $elmcourseid = $course->idnumber;
                        $coursename = $course->fullname;

                        // Get user idnumber.
                        $user = $DB->get_record('user', ['id' => $userid], 'idnumber, firstname, lastname, email');
                        
                        // Get enrolment_id and another field (e.g., status) from local_psaelmsync_logs table.
                        $deets = $DB->get_record('local_psaelmsync_logs', ['elm_course_id' => $elmcourseid, 'user_id' => $userid], 'elm_enrolment_id, class_code, sha256hash');

                        if (!$deets) {

                            // Get the list of email addresses from admin settings
                            $admin_emails = get_config('local_psaelmsync', 'notificationemails');
                            
                            if (!empty($admin_emails)) {

                                $emails = explode(',', $admin_emails);

                                $subject = "User Enrolment Data Lookup Failure";
                                
                                $message = $user->firstname . ' ' . $user->lastname . ': https://learning.gww.gov.bc.ca/user/view.php?id=' . $userid . '\n';
                                $message .= $coursename . ': https://learning.gww.gov.bc.ca/course/view.php?id=' . $courseid . '\n';
                                $message .= 'ELM Course ID: ' . $elmcourseid . '\n';
                                $message .= 'Could not find an associated record in local_psaelmsync_logs for this completion.';
                                
                                // Create a dummy user object for sending the email
                                $dummyuser = new \stdClass();
                                $dummyuser->email = 'noreply-psalssync@learning.gww.gov.bc.ca';
                                $dummyuser->firstname = 'System';
                                $dummyuser->lastname = 'Notifier';
                                $dummyuser->id = -99; // Dummy user id
                                
                                foreach ($emails as $admin_email) {
                                    // Trim to remove any extra whitespace around email addresses
                                    $admin_email = trim($admin_email);
                                    
                                    // Create a recipient user object
                                    $recipient = new \stdClass();
                                    $recipient->email = $admin_email;
                                    $recipient->id = -99; // Dummy user id
                                    $recipient->firstname = 'PSA';
                                    $recipient->lastname = 'Moodle';
                                    
                                    // Send the email
                                    email_to_user($recipient, $dummyuser, $subject, $message);
                                }
                            }
                            // Now stop processing because we don't have enough info to proceed.
                            exit;
                        }

                        $elm_enrolment_id = $deets->elm_enrolment_id;
                        $class_code = $deets->class_code;
                        $sha256hash = $deets->sha256hash;

                        // Setup other static variables
                        $record_id = time();
                        $processed = 0;
                        $enrol_status = 'Complete';
                        $datecreated = date('Y-m-d h:i:s');

                        // `COURSE_COMPLETE_DATE` varchar(100) NOT NULL,
                        // `COURSE_STATE` varchar(100) NOT NULL,
                        // `COURSE_IDENTIFIER` int(11) NOT NULL,
                        // `COURSE_SHORTNAME` varchar(100) NOT NULL,
                        // `FIRST_NAME` varchar(255) NOT NULL,
                        // `LAST_NAME` varchar(255) NOT NULL,
                        // `EMAIL` varchar(255) NOT NULL,
                        // `GUID` varchar(255) NOT NULL,
                        // `USER_EFFECTIVE_DATE` varchar(255) NOT NULL,
                        // `USER_STATE` varchar(255) NOT NULL,
                        // `date_created` datetime NOT NULL,
                        // `date_deleted` datetime DEFAULT NULL,
                        // `date_updated` datetime DEFAULT NULL,
                        // prepare data to post
                        // Setup the actual cURL call
                        $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
                        $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

                        $ch = curl_init($apiurl);

                        $data = [
                            'COURSE_COMPLETE_DATE' => date('Y-m-d'),
                            'COURSE_STATE' => $enrol_status, 
                            'ENROLMENT_ID' => (int) $elm_enrolment_id, 
                            'USER_STATE' => 'Active',
                            'USER_EFFECTIVE_DATE' => '2017-02-14',
                            'COURSE_IDENTIFIER' => (int) $elmcourseid, 
                            'COURSE_SHORTNAME' => $class_code, 
                            'EMAIL' => $user->email,
                            'GUID' => $user->idnumber,
                            'FIRST_NAME' => $user->firstname, 
                            'LAST_NAME' => $user->lastname
                        ];
                        
                        $jsonData = json_encode($data);
                        $options = array(
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $jsonData,
                            CURLOPT_HTTPHEADER => array(
                                "x-cdata-authtoken: " . $apitoken,
                                "Content-Type: application/json",
                                "Content-Length: " . strlen($jsonData)
                                )
                            );
                        curl_setopt_array($ch, $options);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $log = [
                            'record_id' => $record_id,
                            'record_date_created' => $datecreated,
                            'sha256hash' => $sha256hash,
                            'course_id' => $courseid,
                            'elm_course_id' => $elmcourseid,
                            'class_code' => $class_code,
                            'course_name' => $course->fullname,
                            'user_id' => $userid,
                            'user_firstname' => $user->firstname,
                            'user_lastname' => $user->lastname,
                            'user_guid' => $user->idnumber,
                            'user_email' => $user->email,
                            'elm_enrolment_id' => $elm_enrolment_id,
                            'action' => 'Complete',
                            'status' => 'Success',
                            'timestamp' => time(),
                        ];
                        
                        $DB->insert_record('local_psaelmsync_logs', (object)$log);

                    }
                }
            }
        } else {
            error_log('Custom field doesn\'t exist?');
        }
    }

}
