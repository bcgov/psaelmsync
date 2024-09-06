<?php

function xmldb_local_psaelmsync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads database manager.

    // Check if the version is less than the new version we are adding the field in.
    if ($oldversion < 2023090601) {
        // Define field elm_course_id to be added to local_psaelmsync_logs.
        $table = new xmldb_table('local_psaelmsync_logs');
        $field = new xmldb_field('elm_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'course_id'); // New field after 'course_id'

        // Conditionally launch add field elm_course_id.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2023090601, 'local', 'psaelmsync');
    }

    return true;
}
