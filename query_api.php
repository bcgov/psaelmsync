<?php
require_once('../../config.php');
require_login();

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/query_api.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('queryapi', 'local_psaelmsync'));
$PAGE->set_heading(get_string('queryapi', 'local_psaelmsync'));

$apiurl = get_config('local_psaelmsync', 'apiurl'); // Fetch the API URL from plugin settings

echo $OUTPUT->header();
?>
<?php if ($_SERVER['REQUEST_METHOD'] === 'GET') : ?>
    <!-- Tabbed Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard.php">Learner Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-courses.php">Course Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/query_api.php">Manual Processing</a>
    </li>
</ul>
<!-- Form to input the 'from' and 'to' dates -->
<form id="api-query-form" class="mb-3">
    <div class="form-group">
        <label for="from"><?php echo get_string('from', 'local_psaelmsync'); ?></label>
        <input type="datetime-local" id="from" name="from" class="form-control" required>
    </div>
    <div class="form-group">
        <label for="to"><?php echo get_string('to', 'local_psaelmsync'); ?></label>
        <input type="datetime-local" id="to" name="to" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary"><?php echo get_string('submit', 'local_psaelmsync'); ?></button>
</form>
<?php endif ?>
<!-- Result section where the fetched records will be displayed -->
<div id="result-container" class="mt-3"></div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // Get the form element
        var form = document.getElementById('api-query-form');

        // Attach the submit event listener
        form.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent form submission

            // Get the from and to date values
            var fromDate = document.getElementById('from').value;
            var toDate = document.getElementById('to').value;

            // Create a FormData object to hold the form data
            var formData = new FormData();
            formData.append('from', fromDate);
            formData.append('to', toDate);
            formData.append('sesskey', M.cfg.sesskey); // Moodle's session key for security

            // Create an XMLHttpRequest object
            var xhr = new XMLHttpRequest();
            xhr.open('POST', "<?php echo $PAGE->url; ?>", true);

            // Handle the response
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 400) {
                    // Success: Replace the content of the result container with the response
                    document.getElementById('result-container').innerHTML = xhr.responseText;
                } else {
                    // Error: Show an error message
                    document.getElementById('result-container').innerHTML = '<div class="alert alert-danger">Error fetching data from the API.</div>';
                }
            };

            // Handle errors in the request
            xhr.onerror = function() {
                document.getElementById('result-container').innerHTML = '<div class="alert alert-danger">Error fetching data from the API.</div>';
            };

            // Send the AJAX request with the form data
            xhr.send(formData);
        });
    });
</script>


<?php
// Handle the AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    $from = required_param('from', PARAM_TEXT); // Get the 'from' date
    $to = required_param('to', PARAM_TEXT);     // Get the 'to' date

    // Build the API URL with the date filters
    // https://cdata.virtuallearn.ca/api.php/records/enrolment
    // ?order=date_created,asc&filter=date_created,gt,2024-07-25+19%3A29%3A06&filter=date_created,lt,2024-08-20+15%3A00%3A06
    $apiurlfiltered = $apiurl . "&filter=date_created,gt," . urlencode($from) . "&filter=date_created,lt," . urlencode($to);

    // Use cURL to query the API
    $curl = new curl();
    $options = array(
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_HTTPHEADER' => array('Content-Type: application/json')
    );
    $response = $curl->get($apiurlfiltered, $options);

    // Check for cURL errors
    if ($curl->get_errno()) {
        echo '<div class="alert alert-danger">Error: Unable to fetch data from API.</div>';
        echo $OUTPUT->footer();
        exit;
    }

    $data = json_decode($response, true); // Decode the JSON response

    if (!empty($data)) {
        // Display the results in a table
        echo '<table class="table table-striped table-bordered">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>COURSE IDENTIFIER</th>';
        echo '<th>COURSE STATE</th>';
        echo '<th>GUID</th>';
        echo '<th>COURSE SHORTNAME</th>';
        echo '<th>DATE CREATED</th>';
        echo '<th>EMAIL</th>';
        echo '<th>FIRST NAME</th>';
        echo '<th>LAST NAME</th>';
        echo '<th>USER STATE</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Loop through the records and display them
        foreach ($data['records'] as $record) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($record['COURSE_IDENTIFIER']) . '</td>';
            echo '<td>' . htmlspecialchars($record['COURSE_STATE']) . '</td>';
            echo '<td>' . htmlspecialchars($record['GUID']) . '</td>';
            echo '<td>' . htmlspecialchars($record['COURSE_SHORTNAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['date_created']) . '</td>';
            echo '<td>' . htmlspecialchars($record['EMAIL']) . '</td>';
            echo '<td>' . htmlspecialchars($record['FIRST_NAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['LAST_NAME']) . '</td>';
            echo '<td>' . htmlspecialchars($record['USER_STATE']) . '</td>';
            echo '</tr>';
            // print_r($record);
        }

        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<div class="alert alert-warning">No data found for the selected dates.</div>';
    }


    exit;
}

echo $OUTPUT->footer();
