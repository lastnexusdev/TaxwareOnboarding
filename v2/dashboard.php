<?php
session_start();

require_once "auth_check.php";
require_once "db.php";

$currentPage = 'dashboard';

// Make sure we have a valid userid in session
if (!isset($_SESSION['userid'])) {
    echo "Access denied: not logged in properly.";
    exit;
}

// Logging function
function log_message($message) {
    $logfile = 'log.txt';
    file_put_contents($logfile, $message . PHP_EOL, FILE_APPEND);
}

// Fetch techs
$techs_sql = "SELECT UserID, FirstName, LastName FROM Users WHERE Department = 2";
$techs_result = $conn->query($techs_sql);

// Prepare techs data
$techs = [];
if ($techs_result->num_rows > 0) {
    while($row = $techs_result->fetch_assoc()) {
        $techs[] = $row;
    }
}

// Log fetched techs
log_message("Fetched techs: " . json_encode($techs));

// Fetch New Software Release setting
$software_release_sql = "SELECT Setting_Value FROM admin_settings WHERE Setting_Name = 'NewSoftwareRelease'";
$software_release_result = $conn->query($software_release_sql);
$new_software_release = $software_release_result->fetch_assoc()['Setting_Value'] ?? 0;

// Calculate statistics based on user role
if ($_SESSION['department'] == 2) {
    // Tech user - show only their clients
    $stats_sql = "SELECT 
        COUNT(*) as total_clients,
        SUM(CASE WHEN Progress = 100 THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN Progress > 0 AND Progress < 100 THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN Progress = 0 THEN 1 ELSE 0 END) as not_started,
        SUM(CASE WHEN Stalled = 1 THEN 1 ELSE 0 END) as stalled,
        SUM(CASE WHEN Cancelled = 1 THEN 1 ELSE 0 END) as cancelled,
        AVG(Progress) as avg_progress
    FROM Onboarding 
    WHERE AssignedTech = '" . $_SESSION['userid'] . "'";
} else {
    // Admin/Sales - show all clients
    $stats_sql = "SELECT 
        COUNT(*) as total_clients,
        SUM(CASE WHEN Progress = 100 THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN Progress > 0 AND Progress < 100 THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN Progress = 0 THEN 1 ELSE 0 END) as not_started,
        SUM(CASE WHEN Stalled = 1 THEN 1 ELSE 0 END) as stalled,
        SUM(CASE WHEN Cancelled = 1 THEN 1 ELSE 0 END) as cancelled,
        AVG(Progress) as avg_progress
    FROM Onboarding";
}
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tech Dashboard</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
<script>
        function goToDetail(clientId) {
            window.location.href = 'onboarding_detail.php?client_id=' + clientId;
        }

        function toggleTechClients(button) {
            const techBox = button.closest('.tech-box');
            if (!techBox) return;

            const isCollapsed = techBox.classList.toggle('collapsed');
            button.setAttribute('aria-expanded', (!isCollapsed).toString());
            button.setAttribute('title', isCollapsed ? 'Expand clients' : 'Collapse clients');
        }
    </script>
</head>

<body class="page-dashboard">

<?php include 'includes/header.php'; ?>

    <div class="dashboard-container">
        <h2>Tech Dashboard</h2>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card total">
                <h4>Total Clients</h4>
                <div class="number"><?php echo $stats['total_clients']; ?></div>
                <div class="percentage">All active clients</div>
            </div>

            <div class="stat-card completed">
                <h4>Completed</h4>
                <div class="number"><?php echo $stats['completed']; ?></div>
                <div class="percentage">
                    <?php 
                    $completed_percent = $stats['total_clients'] > 0 ? round(($stats['completed'] / $stats['total_clients']) * 100) : 0;
                    echo $completed_percent . '% of total';
                    ?>
                </div>
            </div>

            <div class="stat-card in-progress">
                <h4>In Progress</h4>
                <div class="number"><?php echo $stats['in_progress']; ?></div>
                <div class="percentage">
                    <?php 
                    $progress_percent = $stats['total_clients'] > 0 ? round(($stats['in_progress'] / $stats['total_clients']) * 100) : 0;
                    echo $progress_percent . '% of total';
                    ?>
                </div>
            </div>

            <div class="stat-card not-started">
                <h4>Not Started</h4>
                <div class="number"><?php echo $stats['not_started']; ?></div>
                <div class="percentage">
                    <?php 
                    $not_started_percent = $stats['total_clients'] > 0 ? round(($stats['not_started'] / $stats['total_clients']) * 100) : 0;
                    echo $not_started_percent . '% of total';
                    ?>
                </div>
            </div>

            <div class="stat-card stalled">
                <h4>Stalled</h4>
                <div class="number"><?php echo $stats['stalled']; ?></div>
                <div class="percentage">Needs attention</div>
            </div>

            <div class="stat-card cancelled">
                <h4>Cancelled</h4>
                <div class="number"><?php echo $stats['cancelled']; ?></div>
                <div class="percentage">Inactive</div>
            </div>
        </div>


        <!-- Legend -->
        <div class="legend">
            <h4>Status Legend:</h4>
            <div class="legend-items">
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #D3D3D3;"></div>
                    <span class="legend-label">Cancelled</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #d4edda;"></div>
                    <span class="legend-label">Completed</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #cfe2ff;"></div>
                    <span class="legend-label">Completed Until New Version</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #fff3cd;"></div>
                    <span class="legend-label">In Progress</span>
                </div>
           <div class="legend-item">
                    <div class="legend-color" style="background-color: #FFB5B3;"></div>
                    <span class="legend-label">Stalled</span>
                </div>

            </div>
        </div>

        <!-- Tech Container -->
        <div class="tech-container">
            <?php foreach ($techs as $tech): ?>
                <?php
                // Fetch clients assigned to this tech with counts
                $clients_sql = "SELECT ClientID, ClientName, PhoneNumber, Progress, Completed, ConvertionNeeded AS ConversionStatus, SalesRep, Package, CompletedUntilNewVersion, Cancelled, Stalled, RowColor FROM Onboarding WHERE AssignedTech = '" . $tech['UserID'] . "'";
                $clients_result = $conn->query($clients_sql);
                
                $client_count = $clients_result->num_rows;
                $completed_count = 0;
                $in_progress_count = 0;
                
                // Store clients for display
                $clients = [];
                while($client = $clients_result->fetch_assoc()) {
                    $clients[] = $client;
                    if ($client['Progress'] == 100) {
                        $completed_count++;
                    } elseif ($client['Progress'] > 0 && $client['Progress'] < 100) {
                        $in_progress_count++;
                    }
                }
                ?>
                <div class="tech-box">
                    <div class="tech-header">
                        <h3>
                            <button type="button" class="tech-name-toggle" aria-expanded="true" title="Collapse clients" onclick="toggleTechClients(this)">
                                <?php echo htmlspecialchars($tech['FirstName'] . ' ' . $tech['LastName']); ?>
                                <span class="toggle-icon" aria-hidden="true">▾</span>
                            </button>
                        </h3>
                        <div class="tech-stats">
                            <span class="tech-stat"><?php echo $client_count; ?> Total</span>
                            <span class="tech-stat"><?php echo $completed_count; ?> Complete</span>
                            <span class="tech-stat"><?php echo $in_progress_count; ?> Active</span>
                        </div>
                    </div>
                    <table class="tech-table">
                        <thead>
                            <tr>
                                <th>Client ID</th>
                                <th>Client Name</th>
                                <th>Phone</th>
                                <th>Progress</th>
                                <th>Completed</th>
                                <th>Conversion</th>
                                <th>Sales Rep</th>
                                <th>Package</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($clients) > 0): ?>
                                <?php foreach($clients as $client): ?>
                                    <?php
                                    // Determine the row color
                                    $row_color = '';
                                    if ($client['Cancelled']) {
                                        $row_color = '#D3D3D3';
                                    } elseif ($client['Progress'] == 100) {
                                        if ($new_software_release == 0) {
                                            $row_color = '#ADD8E6';
                                            if ($client['CompletedUntilNewVersion'] == 0) {
                                                $update_completed_until_sql = "UPDATE Onboarding SET CompletedUntilNewVersion = 1 WHERE ClientID = '" . $client['ClientID'] . "'";
                                                $conn->query($update_completed_until_sql);
                                            }
                                        } elseif ($new_software_release == 1) {
                                            $row_color = '#32CD32';
                                            if ($client['Completed'] == 0) {
                                                $update_completed_sql = "UPDATE Onboarding SET Completed = 1 WHERE ClientID = '" . $client['ClientID'] . "'";
                                                $conn->query($update_completed_sql);
                                            }
                                        }
                                    } elseif ($client['Stalled']) {
                                        $row_color = '#FFB5B3';
                                    } elseif ($client['Progress'] > 0) {
                                        $row_color = '#FFD700';
                                    }

                                    // Update the RowColor in the database if it has changed
                                    if ($client['RowColor'] !== $row_color) {
                                        $update_color_sql = "UPDATE Onboarding SET RowColor = '$row_color' WHERE ClientID = '" . $client['ClientID'] . "'";
                                        $conn->query($update_color_sql);
                                        log_message("Updated RowColor for ClientID " . $client['ClientID'] . " to " . $row_color);
                                    }
                                    ?>
                                    <tr style="background-color: <?php echo htmlspecialchars($row_color); ?>" onclick="goToDetail('<?php echo htmlspecialchars($client['ClientID']); ?>')">
                                        <td><strong><?php echo htmlspecialchars($client['ClientID']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($client['ClientName']); ?></td>
                                        <td><?php echo htmlspecialchars($client['PhoneNumber']); ?></td>
                                        <td>
                                            <div class="progress-cell">
                                                <div class="mini-progress-bar">
                                                    <div class="mini-progress-fill" style="width: <?php echo round($client['Progress'], 2); ?>%"></div>
                                                </div>
                                                <span class="progress-text"><?php echo (int) round($client['Progress']); ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $client['Completed'] ? 'status-yes' : 'status-no'; ?>">
                                                <?php echo $client['Completed'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($client['ConversionStatus']); ?></td>
                                        <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                                        <td><?php echo htmlspecialchars($client['Package']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="no-clients">
                                            <h4>No Clients Assigned</h4>
                                            <p>This technician has no active clients.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>