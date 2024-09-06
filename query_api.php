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

<!-- Form for date selection -->
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

<!-- Result section for displaying data -->
<div id="result-container" class="mt-3"></div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('api-query-form');

        form.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent form submission

            var fromDate = document.getElementById('from').value;
            var toDate = document.getElementById('to').value;

            var formData = new FormData();
            formData.append('from', fromDate);
            formData.append('to', toDate);
            formData.append('sesskey', M.cfg.sesskey); // Moodle's session key for security

            var xhr = new XMLHttpRequest();
            xhr.open('POST', "<?php echo $PAGE->url; ?>", true);

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 400) {
                    var response = JSON.parse(xhr.responseText);

                    var resultContainer = document.getElementById('result-container');
                    if (response.error) {
                        resultContainer.innerHTML = '<div class="alert alert-danger">' + response.error + '</div>';
                    } else {
                        var records = response.records;
                        var tableHTML = '<table class="table table-striped table-bordered">';
                        tableHTML += '<thead><tr>';
                        tableHTML += '<th>COURSE IDENTIFIER</th><th>COURSE STATE</th><th>GUID</th><th>COURSE SHORTNAME</th><th>DATE CREATED</th><th>EMAIL</th><th>FIRST NAME</th><th>LAST NAME</th><th>USER STATE</th>';
                        tableHTML += '</tr></thead><tbody>';

                        records.forEach(function(record) {
                            tableHTML += '<tr>';
                            tableHTML += '<td>' + record.COURSE_IDENTIFIER + '</td>';
                            tableHTML += '<td>' + record.COURSE_STATE + '</td>';
                            tableHTML += '<td>' + record.GUID + '</td>';
                            tableHTML += '<td>' + record.COURSE_SHORTNAME + '</td>';
                            tableHTML += '<td>' + record.date_created + '</td>';
                            tableHTML += '<td>' + record.EMAIL + '</td>';
                            tableHTML += '<td>' + record.FIRST_NAME + '</td>';
                            tableHTML += '<td>' + record.LAST_NAME + '</td>';
                            tableHTML += '<td>' + record.USER_STATE + '</td>';
                            tableHTML += '</tr>';
                        });

                        tableHTML += '</tbody></table>';
                        resultContainer.innerHTML = tableHTML;
                    }
                } else {
                    document.getElementById('result-container').innerHTML = '<div class="alert alert-danger">Error fetching data from the API.</div>';
                }
            };

            xhr.onerror = function() {
                document.getElementById('result-container').innerHTML = '<div class="alert alert-danger">Error fetching data from the API.</div>';
            };

            xhr.send(formData);
        });
    });
</script>

<?php
// Handle the AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    $from = required_param('from', PARAM_TEXT); // Get the 'from' date
    $to = required_param('to', PARAM_TEXT);     // Get the 'to' date

    $apiurlfiltered = $apiurl . "&filter=date_created,gt," . urlencode($from) . "&filter=date_created,lt," . urlencode($to);

    $curl = new curl();
    $options = array(
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_HTTPHEADER' => array('Content-Type: application/json')
    );
    $response = $curl->get($apiurlfiltered, $options);

    if ($curl->get_errno()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unable to fetch data from API']);
        exit;
    }

    $data = json_decode($response, true);

    if (!empty($data)) {
        header('Content-Type: application/json');
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'No data found for the selected dates.']);
    }

    exit;
}

echo $OUTPUT->footer();
