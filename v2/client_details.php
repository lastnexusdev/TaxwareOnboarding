<?php
require_once "auth_check.php"; // forces login check
include 'db.php';

// Check if the user is logged in
if (!isset($_SESSION['userid'])) {
    echo "Access denied.";
    exit;
}

$client_id = $_GET['client_id'];

// Fetch client details
$client_sql = "SELECT * FROM Onboarding WHERE ClientID = '$client_id'";
$client_result = $conn->query($client_sql);
$client = $client_result->fetch_assoc();

if (!$client) {
    echo "Client not found.";
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Onboarding Details</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
        <link rel="stylesheet" type="text/css" href="css/client_details.css">
</head>
<body>
    <div class="container">
        <h2>Onboarding Details for <?php echo htmlspecialchars($client['ClientName']); ?></h2>
        <div class="checklist">
            <h3>Pre-Installation Preparation</h3>
            <ul>
                <li>Verify Client Details</li>
                <li>Confirm client’s name and contact information3.</li>
                <li class="tooltip">Specific requirements and configuration
                    <span class="tooltiptext">
                        - Do they need a Local, Network, or Cloud Setup?<br>
                        - Multi Office2?<br>
                        - What years need installing (e-filable)?
                    </span>
                </li>
                <li>Schedule Appointment if they don't have time for install at this moment</li>
            </ul>
            <h3>Download</h3>
            <ul>
                <li>Download the latest version of Taxware software</li>
                <li>Inform Client Of New Version And Possible Time Frames</li>
                <li>Start the Taxware installation wizard</li>
                <li>Follow the on-screen instructions for standard installation.</li>
            </ul>
            <h3>Setup</h3>
            <ul>
                <li>Initial Setup</li>
                <li>Enter Userid into all Software Installed</li>
                <li>Configure the basic settings as per the client's requirements. (explain some of the settings)</li>
                <li>Provide instructions on how to manage user accounts. (winuser, Passwords,)</li>
            </ul>
            <h3>Testing</h3>
            <ul>
                <li>Run the software to ensure it starts correctly.</li>
                <li>Perform basic functionality tests to confirm the installation is successful.</li>
                <li>Provide a brief walkthrough of the software features.</li>
                <li>Demonstrate how to perform basic tasks and use essential functions (Taxware Connect, Videos, Website, Updates)</li>
            </ul>
            <h3>Client Data Conversion</h3>
            <ul>
                <li>Assess the data currently used by the client.</li>
                <li>Plan and execute the conversion of client data</li>
                <li>Verify the accuracy and completeness of the converted data.</li>
            </ul>
            <h3>Final Steps</h3>
            <ul>
                <li>Ensure the client knows how to contact support for further assistance.</li>
                <li>Offer links to online resources and tutorials.</li>
                <li>Provide information on upcoming training sessions or webinars.</li>
                <li>Schedule a follow-up call to address any new questions they might have</li>
            </ul>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>
