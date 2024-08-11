<?php

namespace local_psaelmsync\task;

defined('MOODLE_INTERNAL') || die();

class process_course_completion extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('process_course_completion', 'local_psaelmsync');
    }

    public function execute() {
        // The cron task will trigger the observer.
    }
}

