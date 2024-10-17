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

    // Upgrade step for adding new types of logging fields, as well as notes and admin attribution to logs
    if ($oldversion < 2024101601) {

        // Define the new fields in local_psaelmsync_runs table.
        $table = new xmldb_table('local_psaelmsync_runs');
        $field1 = new xmldb_field('enrolnewcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'skippedcount');
        $field2 = new xmldb_field('manualenrolcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'skippedcount');
        $field3 = new xmldb_field('manualsuspendcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'skippedcount');

        // Conditionally launch the add field statements for each field.
        foreach ([$field1, $field2, $field3] as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Define new fields for local_psaelmsync_logs table.
        $table_logs = new xmldb_table('local_psaelmsync_logs');
        $field_notes = new xmldb_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timestamp');
        $field_admin_user_id = new xmldb_field('admin_user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'notes');

        // Conditionally launch the add field statements for local_psaelmsync_logs.
        if (!$dbman->field_exists($table_logs, $field_notes)) {
            $dbman->add_field($table_logs, $field_notes);
        }

        if (!$dbman->field_exists($table_logs, $field_admin_user_id)) {
            $dbman->add_field($table_logs, $field_admin_user_id);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2024101601, 'local', 'psaelmsync');
    }

    return true;
}
