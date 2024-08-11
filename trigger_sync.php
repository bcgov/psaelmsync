<?php
require_once('../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/trigger_sync.php');
$PAGE->set_context($context);
$PAGE->set_title('Trigger PSA Enrol Sync');


echo $OUTPUT->header();
echo $OUTPUT->heading('Trigger PSA Enrol Sync');

require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

// Call the sync function.
local_psaelmsync_sync();

echo $OUTPUT->notification('PSA Enrol Sync has been triggered.', 'notifysuccess');

echo $OUTPUT->footer();
