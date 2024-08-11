<?php
namespace local_psaelmsync\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

class sync_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('sync_task', 'local_psaelmsync');
    }

    public function execute() {
        // Check if the plugin is enabled before executing the sync.
        if (!get_config('local_psaelmsync', 'enabled')) {
            mtrace('PSA Enrol Sync: Task is disabled.');
            return;
        }

        local_psaelmsync_sync();
    }
}
