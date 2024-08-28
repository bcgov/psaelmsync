<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_psaelmsync', get_string('pluginname', 'local_psaelmsync'));

    $settings->add(new admin_setting_heading('local_psaelmsync/dashboard', 
        get_string('logs', 'local_psaelmsync'), 
        html_writer::link(new moodle_url('/local/psaelmsync/dashboard.php'), 
        get_string('viewlogs', 'local_psaelmsync'))));

    $settings->add(new admin_setting_configtext('local_psaelmsync/dashboardlink',
        '',
        '',
        html_writer::link(new moodle_url('/local/psaelmsync/dashboard-intake.php'), 
        get_string('viewintake', 'local_psaelmsync')),
        PARAM_RAW));
    
    $settings->add(new admin_setting_configcheckbox('local_psaelmsync/enabled',
        get_string('enabled', 'local_psaelmsync'),
        get_string('enabled_desc', 'local_psaelmsync'),
        1));

    $settings->add(new admin_setting_configtext('local_psaelmsync/apiurl', 
        get_string('apiurl', 'local_psaelmsync'), 
        get_string('apiurl_desc', 'local_psaelmsync'), 
        '', PARAM_URL));

    $settings->add(new admin_setting_configtext('local_psaelmsync/apitoken', 
        get_string('apitoken', 'local_psaelmsync'), 
        get_string('apitoken_desc', 'local_psaelmsync'), 
        '', PARAM_TEXT));
    
    $settings->add(new admin_setting_configtext('local_psaelmsync/datefilterminutes', 
        get_string('datefilterminutes', 'local_psaelmsync'), 
        get_string('datefilterminutes_desc', 'local_psaelmsync'), 
        120, PARAM_INT));
    
    $settings->add(new admin_setting_configtext('local_psaelmsync/notificationhours', 
        get_string('notificationhours', 'local_psaelmsync'), 
        get_string('notificationhours_desc', 'local_psaelmsync'), 
        1, PARAM_INT));

    $settings->add(new admin_setting_configtext(
        'local_psaelmsync/completion_apiurl',
        get_string('completion_apiurl', 'local_psaelmsync'),
        get_string('completion_apiurl_desc', 'local_psaelmsync'),
        '', PARAM_URL));

    $settings->add(new admin_setting_configtext(
        'local_psaelmsync/completion_apitoken',
        get_string('completion_apitoken', 'local_psaelmsync'),
        get_string('completion_apitoken_desc', 'local_psaelmsync'),
        '', PARAM_ALPHANUMEXT));
    
    $settings->add(new admin_setting_configtext(
        'local_psaelmsync/notificationemails',
        get_string('notificationemails', 'local_psaelmsync'),
        get_string('notificationemails_desc', 'local_psaelmsync'),
        '',
        PARAM_TEXT));

    $ADMIN->add('localplugins', $settings);
}
