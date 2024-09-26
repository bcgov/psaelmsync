# PSALS Sync

PSALS Sync is a Moodle plugin that reads course enrolment data sent by ELM and 
sends completion data back.

* Tested up to PHP 8.2 and Moodle 4.3.
* Runs under PHP 7.4 and Moodle 3.11.18.

## Features 

* Custom created to handle a stream of enrol and suspend records in an efficient and verifiable way.
* Unified dashboard logging all enrolments, drops, completions, and errors in a searchable interface.
* Flexible intake and pass-through of data.
* Error notification to administrators when problems arise.
* Monitors for a blocked feed and notifies admins.

We start history on September 3rd 2024 with the first real intake runs beginning
at 06:05 on Sept 4. Additionally, approximately 8400 records were imported 
into local_psaelmsync_logs of all existing enrolments on Sept 4 that existed
before the new process was implemented. These records were brought in with an
"Action" of "Imported" as they were not actually processed by the plugin and
we'll want to know that.