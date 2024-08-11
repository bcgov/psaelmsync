<?php

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => 'local_psaelmsync\observer::course_completed'
    ]
];
