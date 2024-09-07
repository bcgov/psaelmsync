<?php

function xmldb_local_psaelmsync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads the database manager.

    // Upgrade step for adding elm_course_id to local_psaelmsync_logs.
    if ($oldversion < 2024090606) {
        // Define the new field elm_course_id.
        $table = new xmldb_table('local_psaelmsync_logs');
        $field = new xmldb_field('elm_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'course_id');

        // Conditionally launch the add field statement.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2024090606, 'local', 'psaelmsync');
    }

    return true;
}
