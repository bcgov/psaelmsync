# PSALS Sync

PSALS Sync is a Moodle plugin that reads course enrolment data sent by ELM and 
sends completion data back.

* Built under PHP 8.1 and Moodle 4.2. 
* Runs under PHP 7.4 and Moodle 3.11.18.

## Features 

* Custom created to handle a stream of enrol and suspend records in an efficient and verifiable way.
* Unified dashboard logging all enrolments, drops, completions, and errors in a searchable interface.
* Flexible intake and pass-through of data.
* Error notification to administrators when problems arise.
* Monitors for a blocked feed and notifies admins.
