<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/psaelmsync:viewlogs' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            // 'manager' => CAP_ALLOW,
            // 'teacher' => CAP_ALLOW,
            // 'editingteacher' => CAP_ALLOW,
            'admin' => CAP_ALLOW,
        ),
    ),
);