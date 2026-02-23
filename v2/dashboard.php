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
<style>
    body {
        background: #f5f5f5 url('https://kb.taxwaresystems.com/web_texture_mirrored.svg');
        background-size: cover;
        background-attachment: fixed;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    /* Top Logo Header */
    .top-logo-header {
        background-color: #9e4a3d;
        padding: 15px 0;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        margin-bottom: 20px;
    }

    .top-logo-header img {
        height: 40px;
        width: auto;
    }

    .dashboard-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 20px;
    }

    h2 {
        text-align: center;
        color: #333;
        margin-bottom: 30px;
        font-size: 32px;
        font-weight: 700;
    }

    /* Stats Overview Cards */
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #9e4a3d 0%, #7a3a2f 100%);
    }

    .stat-card.total::before {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    }

    .stat-card.completed::before {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    }

    .stat-card.in-progress::before {
        background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
    }

    .stat-card.not-started::before {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    }

    .stat-card.stalled::before {
        background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%);
    }

    .stat-card.cancelled::before {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    }

    .stat-card h4 {
        margin: 0 0 10px 0;
        color: #666;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .stat-card .number {
        font-size: 36px;
        font-weight: bold;
        color: #333;
        margin: 0;
    }

    .stat-card .percentage {
        font-size: 14px;
        color: #666;
        margin-top: 5px;
    }

    /* Legend */
    .legend {
        background: white;
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: center;
    }

    .legend h4 {
        margin: 0;
        color: #333;
        font-size: 14px;
        font-weight: 600;
        margin-right: 10px;
    }

    .legend-items {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        flex: 1;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .legend-color {
        width: 20px;
        height: 20px;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .legend-label {
        font-size: 13px;
        color: #555;
        font-weight: 500;
    }

    /* Tech Container */
    .tech-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
        gap: 20px;
    }

    .tech-box {
        background-color: #fff;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .tech-box:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .tech-header {
        background: linear-gradient(135deg, #9e4a3d 0%, #7a3a2f 100%);
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .tech-header h3 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
        color: #fff !important;
    }

    .tech-stats {
        display: flex;
        gap: 15px;
        font-size: 13px;
    }

    .tech-stat {
        background: rgba(255, 255, 255, 0.2);
        padding: 5px 12px;
        border-radius: 12px;
        font-weight: 500;
    }

    /* Table Styling */
    .tech-table {
        width: 100%;
        border-collapse: collapse;
    }

    .tech-table th {
        background-color: #f8f9fa;
        color: #495057;
        padding: 12px 10px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        border-bottom: 2px solid #dee2e6;
    }

    .tech-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #dee2e6;
        font-size: 14px;
    }

    .tech-table tbody tr {
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .tech-table tbody tr:hover {
        background-color: #f8f9fa;
    }

    /* Row Colors */
    .tech-table tbody tr[style*="background-color: #D3D3D3"] {
        background-color: #D3D3D3 !important;
        color: #666;
    }

    .tech-table tbody tr[style*="background-color: #32CD32"] {
        background-color: #d4edda !important;
    }

    .tech-table tbody tr[style*="background-color: #ADD8E6"] {
        background-color: #cfe2ff !important;
    }

    .tech-table tbody tr[style*="background-color: #FFA500"] {
        background-color: #fff3cd !important;
    }

    .tech-table tbody tr[style*="background-color: #FFD700"] {
        background-color: #fff3cd !important;
    }

    /* Progress Bar in Table */
    .progress-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .mini-progress-bar {
        flex: 1;
        height: 8px;
        background-color: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
    }

    .mini-progress-fill {
        height: 100%;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        transition: width 0.3s ease;
    }

    .progress-text {
        font-weight: 600;
        color: #495057;
        min-width: 45px;
        text-align: right;
    }

    /* Status Badge */
    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }

    .status-yes {
        background-color: #d4edda;
        color: #155724;
    }

    .status-no {
        background-color: #f8d7da;
        color: #721c24;
    }

    /* No Clients Message */
    .no-clients {
        padding: 40px;
        text-align: center;
        color: #999;
    }

    .no-clients h4 {
        font-size: 18px;
        margin-bottom: 10px;
    }

    /* Quick Actions */
    .quick-actions {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .quick-actions h4 {
        margin: 0 0 15px 0;
        color: #333;
        font-size: 16px;
        font-weight: 600;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .action-btn {
        padding: 10px 20px;
        background: linear-gradient(135deg, #9e4a3d 0%, #7a3a2f 100%);
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(158, 74, 61, 0.2);
    }

    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(158, 74, 61, 0.3);
    }

    .action-btn.secondary {
        background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .tech-container {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-overview {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }

        .tech-table {
            font-size: 12px;
        }

        .tech-table th,
        .tech-table td {
            padding: 8px 6px;
        }
    }

    /* Loading State */
    .loading {
        text-align: center;
        padding: 40px;
        color: #999;
    }

    .loading::after {
        content: '';
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #9e4a3d;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>    <script>
        function goToDetail(clientId) {
            window.location.href = 'onboarding_detail.php?client_id=' + clientId;
        }
    </script>
</head>

<body>

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
                        <h3><?php echo htmlspecialchars($tech['FirstName'] . ' ' . $tech['LastName']); ?></h3>
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