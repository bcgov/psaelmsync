<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_psaelmsync\task\sync_task',
        'blocking' => 0,
        'minute' => '5,15,25,35,45,55',
        'hour' => '6-18',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ],
    [
        'classname' => 'local_psaelmsync\task\process_course_completion',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ],
];
