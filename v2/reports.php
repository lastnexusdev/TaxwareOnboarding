<?php
require_once "auth_check.php"; // forces login check
include 'db.php';

$currentPage = 'reports';

// Check if the user is logged in
if (!isset($_SESSION['userid'])) {
    echo "Access denied.";
    exit;
}

// Fetch data for reports
// Fetch data for Tech Assignments
$tech_assignments_sql = "SELECT u.FirstName, u.LastName, COUNT(o.ClientID) AS AssignedClients
                         FROM Users u
                         LEFT JOIN Onboarding o ON u.UserID = o.AssignedTech
                         WHERE u.Department = 2
                         GROUP BY u.UserID";
$tech_assignments_result = $conn->query($tech_assignments_sql);
$tech_assignments = [];
while ($row = $tech_assignments_result->fetch_assoc()) {
    $tech_assignments[] = $row;
}

// Fetch data for Client Status with all client info
$client_status_sql = "SELECT o.ClientID, o.ClientName, o.Progress, o.CompletedUntilNewVersion, 
                      o.Cancelled, o.Stalled, o.AssignedTech, o.SalesRep, o.Package, o.PhoneNumber,
                      u.FirstName, u.LastName,
                      CASE
                          WHEN o.Cancelled = 1 THEN 'Canceled'
                          WHEN o.Progress = 100 THEN 'Completed'
                          WHEN o.CompletedUntilNewVersion = 1 THEN 'CompletedUntilNewVersion'
                          WHEN o.Stalled = 1 THEN 'Stalled'
                          WHEN o.Progress = 0 THEN 'Not Started'
                          WHEN o.Progress > 0 AND o.Progress < 100 THEN 'In Progress'
                          ELSE 'Unknown'
                      END AS Status
                      FROM Onboarding o
                      LEFT JOIN Users u ON o.AssignedTech = u.UserID
                      ORDER BY o.ClientName";
$client_status_result = $conn->query($client_status_sql);
$client_status = [];
while ($row = $client_status_result->fetch_assoc()) {
    $client_status[] = $row;
}

// Calculate totals and percentages
$total_clients = count($client_status);
$completed_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'Completed'; }));
$in_progress_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'In Progress'; }));
$not_started_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'Not Started'; }));
$stalled_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'Stalled'; }));
$canceled_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'Canceled'; }));
$pending_count = count(array_filter($client_status, function ($row) { return $row['Status'] == 'CompletedUntilNewVersion'; }));

// Calculate percentages
$completed_percent = $total_clients > 0 ? round(($completed_count / $total_clients) * 100, 1) : 0;
$in_progress_percent = $total_clients > 0 ? round(($in_progress_count / $total_clients) * 100, 1) : 0;
$not_started_percent = $total_clients > 0 ? round(($not_started_count / $total_clients) * 100, 1) : 0;
$stalled_percent = $total_clients > 0 ? round(($stalled_count / $total_clients) * 100, 1) : 0;
$canceled_percent = $total_clients > 0 ? round(($canceled_count / $total_clients) * 100, 1) : 0;
$pending_percent = $total_clients > 0 ? round(($pending_count / $total_clients) * 100, 1) : 0;

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Override body background for reports page */
        body {
            background-color: #f4f4f4;
        }

        /* Stats Overview Cards */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card h4 {
            margin: 0 0 10px 0;
            font-size: 12px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            margin: 0 0 10px 0;
        }

        .stat-card .percentage {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        /* Progress bar at bottom of stat cards */
        .stat-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            background-color: rgba(255, 255, 255, 0.3);
            transition: width 0.5s ease;
        }

        .stat-card.total {
            background-color: #6c757d;
        }

        .stat-card.completed {
            background-color: #32CD32;
        }

        .stat-card.in-progress {
            background-color: #FFD700;
        }

        .stat-card.not-started {
            background-color: #87CEEB;
        }

        .stat-card.stalled {
            background-color: #FFA500;
        }

        .stat-card.canceled {
            background-color: #D3D3D3;
        }

        .stat-card.pending {
            background-color: #ADD8E6;
        }

        /* Button Container */
        .button-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .button-container button {
            padding: 10px 20px;
            background-color: #007BFF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .button-container button:hover {
            background-color: #0056b3;
        }

        .button-container button.active {
            background-color: #0056b3;
            font-weight: bold;
        }

        /* Report Sections */
        .report-section {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .report-section.active {
            display: block;
        }

        .report-container {
            background-color: #f4f4f4;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .chart-container {
            max-width: 600px;
            margin: 0 auto;
            height: 400px;
            position: relative;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
        }

        .report-section h3 {
            color: #555;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #007BFF;
            padding-bottom: 10px;
        }

        /* Client List */
        .client-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .client-list li {
            background-color: white;
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.3s ease;
            border: 1px solid #ddd;
        }

        .client-list li:hover {
            background-color: #f9f9f9;
        }

        .client-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .client-id {
            background-color: #007BFF;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
        }

        .progress-badge {
            background-color: #32CD32;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            margin-left: 10px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
            background-color: white;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        /* All Clients Table */
        .all-clients-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .all-clients-table th,
        .all-clients-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .all-clients-table th {
            background-color: #007BFF;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }

        .all-clients-table tr:hover {
            background-color: #f5f5f5;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background-color: #32CD32;
            color: white;
        }

        .status-in-progress {
            background-color: #FFD700;
            color: #333;
        }

        .status-not-started {
            background-color: #87CEEB;
            color: white;
        }

        .status-stalled {
            background-color: #FFA500;
            color: white;
        }

        .status-canceled {
            background-color: #D3D3D3;
            color: #333;
        }

        .status-pending {
            background-color: #ADD8E6;
            color: white;
        }
    </style>
    <script>
        function showSection(section) {
            // Remove active class from all sections and buttons
            var sections = document.getElementsByClassName('report-section');
            var buttons = document.querySelectorAll('.button-container button');
            
            for (var i = 0; i < sections.length; i++) {
                sections[i].classList.remove('active');
            }
            
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove('active');
            }
            
            // Add active class to selected section and button
            document.getElementById(section).classList.add('active');
            event.target.classList.add('active');
        }

        function renderCharts() {
            var techAssignmentsCtx = document.getElementById('techAssignmentsChart').getContext('2d');
            var clientStatusCtx = document.getElementById('clientStatusChart').getContext('2d');

            // Color palette matching dashboard
            var techColors = [
                'rgba(0, 123, 255, 0.8)',
                'rgba(40, 167, 69, 0.8)',
                'rgba(255, 193, 7, 0.8)',
                'rgba(220, 53, 69, 0.8)',
                'rgba(23, 162, 184, 0.8)',
                'rgba(108, 117, 125, 0.8)'
            ];

            // Data for Tech Assignments
            var techAssignmentsData = {
                labels: [<?php echo implode(',', array_map(function ($row) { return '"' . $row['FirstName'] . ' ' . $row['LastName'] . '"'; }, $tech_assignments)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_map(function ($row) { return $row['AssignedClients']; }, $tech_assignments)); ?>],
                    backgroundColor: techColors,
                    borderWidth: 2,
                    borderColor: 'white'
                }]
            };

            // Data for Client Status
            var clientStatusData = {
                labels: ['Completed', 'Not Started', 'Completed Until New Version', 'In Progress', 'Stalled', 'Canceled'],
                datasets: [{
                    data: [
                        <?php echo $completed_count; ?>,
                        <?php echo $not_started_count; ?>,
                        <?php echo $pending_count; ?>,
                        <?php echo $in_progress_count; ?>,
                        <?php echo $stalled_count; ?>,
                        <?php echo $canceled_count; ?>
                    ],
                    backgroundColor: [
                        'rgba(50, 205, 50, 0.8)',    // Completed - Green
                        'rgba(135, 206, 235, 0.8)',  // Not Started - Light Blue
                        'rgba(173, 216, 230, 0.8)',  // Completed Until New - Lighter Blue
                        'rgba(255, 215, 0, 0.8)',    // In Progress - Yellow
                        'rgba(255, 165, 0, 0.8)',    // Stalled - Orange
                        'rgba(211, 211, 211, 0.8)'   // Canceled - Gray
                    ],
                    borderWidth: 2,
                    borderColor: 'white'
                }]
            };

            var chartOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12,
                                family: "'Arial', sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        }
                    }
                }
            };

            new Chart(techAssignmentsCtx, {
                type: 'doughnut',
                data: techAssignmentsData,
                options: chartOptions
            });

            new Chart(clientStatusCtx, {
                type: 'doughnut',
                data: clientStatusData,
                options: chartOptions
            });
        }

        window.onload = function () {
            renderCharts();
            // Show all clients by default
            document.getElementById('all-clients').classList.add('active');
            document.querySelector('.button-container button').classList.add('active');
        };
    </script>
</head>
<body>
<?php include 'includes/header.php'; ?>    
    <div class="container">
        <h2>Analytics & Reports</h2>
        
        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card total">
                <h4>Total Clients</h4>
                <p class="number"><?php echo $total_clients; ?></p>
                <p class="percentage">All clients in system</p>
                <div class="stat-progress" style="width: 100%;"></div>
            </div>
            
            <div class="stat-card completed">
                <h4>Completed</h4>
                <p class="number"><?php echo $completed_count; ?></p>
                <p class="percentage"><?php echo $completed_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $completed_percent; ?>%;"></div>
            </div>
            
            <div class="stat-card in-progress">
                <h4>In Progress</h4>
                <p class="number"><?php echo $in_progress_count; ?></p>
                <p class="percentage"><?php echo $in_progress_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $in_progress_percent; ?>%;"></div>
            </div>
            
            <div class="stat-card not-started">
                <h4>Not Started</h4>
                <p class="number"><?php echo $not_started_count; ?></p>
                <p class="percentage"><?php echo $not_started_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $not_started_percent; ?>%;"></div>
            </div>
            
            <div class="stat-card stalled">
                <h4>Stalled</h4>
                <p class="number"><?php echo $stalled_count; ?></p>
                <p class="percentage"><?php echo $stalled_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $stalled_percent; ?>%;"></div>
            </div>
            
            <div class="stat-card canceled">
                <h4>Canceled</h4>
                <p class="number"><?php echo $canceled_count; ?></p>
                <p class="percentage"><?php echo $canceled_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $canceled_percent; ?>%;"></div>
            </div>
            
            <?php if ($pending_count > 0): ?>
            <div class="stat-card pending">
                <h4>Pending New Version</h4>
                <p class="number"><?php echo $pending_count; ?></p>
                <p class="percentage"><?php echo $pending_percent; ?>% of total</p>
                <div class="stat-progress" style="width: <?php echo $pending_percent; ?>%;"></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Report Navigation -->
        <div class="button-container">
            <button onclick="showSection('all-clients')">All Clients</button>
            <button onclick="showSection('tech-assignments')">Tech Assignments</button>
            <button onclick="showSection('client-status')">Client Status Overview</button>
            <button onclick="showSection('completed')">Completed (<?php echo $completed_count; ?>)</button>
            <button onclick="showSection('in-progress')">In Progress (<?php echo $in_progress_count; ?>)</button>
            <button onclick="showSection('not-started')">Not Started (<?php echo $not_started_count; ?>)</button>
            <button onclick="showSection('stalled')">Stalled (<?php echo $stalled_count; ?>)</button>
            <button onclick="showSection('canceled')">Canceled (<?php echo $canceled_count; ?>)</button>
            <?php if ($pending_count > 0): ?>
                <button onclick="showSection('completed-until-new-version')">Pending New Version (<?php echo $pending_count; ?>)</button>
            <?php endif; ?>
        </div>

        <!-- All Clients Section -->
        <div id="all-clients" class="report-section">
            <div class="report-container">
                <h3>All Clients (<?php echo $total_clients; ?>)</h3>
                <?php if ($total_clients > 0): ?>
                    <table class="all-clients-table">
                        <thead>
                            <tr>
                                <th>Client ID</th>
                                <th>Client Name</th>
                                <th>Assigned Tech</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Sales Rep</th>
                                <th>Package</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($client_status as $client): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($client['ClientID']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($client['ClientName']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($client['FirstName'] . ' ' . $client['LastName']); ?></td>
                                    <td><?php echo round($client['Progress'], 1); ?>%</td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch($client['Status']) {
                                            case 'Completed':
                                                $status_class = 'status-completed';
                                                $status_text = 'Completed';
                                                break;
                                            case 'In Progress':
                                                $status_class = 'status-in-progress';
                                                $status_text = 'In Progress';
                                                break;
                                            case 'Not Started':
                                                $status_class = 'status-not-started';
                                                $status_text = 'Not Started';
                                                break;
                                            case 'Stalled':
                                                $status_class = 'status-stalled';
                                                $status_text = 'Stalled';
                                                break;
                                            case 'Canceled':
                                                $status_class = 'status-canceled';
                                                $status_text = 'Canceled';
                                                break;
                                            case 'CompletedUntilNewVersion':
                                                $status_class = 'status-pending';
                                                $status_text = 'Pending New Version';
                                                break;
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                                    <td><?php echo htmlspecialchars($client['Package']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <h4>No Clients Found</h4>
                        <p>There are currently no clients in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tech Assignments Section -->
        <div id="tech-assignments" class="report-section">
            <div class="report-container">
                <h3>Tech Assignments Overview</h3>
                <div class="chart-container">
                    <canvas id="techAssignmentsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Client Status Section -->
        <div id="client-status" class="report-section">
            <div class="report-container">
                <h3>Client Status Distribution</h3>
                <div class="chart-container">
                    <canvas id="clientStatusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Completed Clients -->
        <div id="completed" class="report-section">
            <div class="report-container">
                <h3>Completed Clients</h3>
                <?php 
                $completed = array_filter($client_status, function ($row) { return $row['Status'] == 'Completed'; });
                if (count($completed) > 0): 
                ?>
                    <ul class="client-list">
                        <?php foreach ($completed as $client): ?>
                            <li>
                                <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                                <div>
                                    <span class="progress-badge"><?php echo round($client['Progress'], 1); ?>%</span>
                                    <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-data">No completed clients found.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Not Started Clients -->
        <div id="not-started" class="report-section">
            <div class="report-container">
                <h3>Not Started Clients</h3>
                <?php 
                $not_started = array_filter($client_status, function ($row) { return $row['Status'] == 'Not Started'; });
                if (count($not_started) > 0): 
                ?>
                    <ul class="client-list">
                        <?php foreach ($not_started as $client): ?>
                            <li>
                                <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                                <div>
                                    <span class="progress-badge" style="background-color: #87CEEB;"><?php echo round($client['Progress'], 1); ?>%</span>
                                    <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-data">No clients waiting to start.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- In Progress Clients -->
        <div id="in-progress" class="report-section">
            <div class="report-container">
                <h3>In Progress Clients</h3>
                <?php 
                $in_progress = array_filter($client_status, function ($row) { return $row['Status'] == 'In Progress'; });
                if (count($in_progress) > 0): 
                ?>
                    <ul class="client-list">
                        <?php foreach ($in_progress as $client): ?>
                            <li>
                                <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                                <div>
                                    <span class="progress-badge" style="background-color: #FFD700;"><?php echo round($client['Progress'], 1); ?>%</span>
                                    <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-data">No clients currently in progress.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stalled Clients -->
        <div id="stalled" class="report-section">
            <div class="report-container">
                <h3>Stalled Clients</h3>
                <?php 
                $stalled = array_filter($client_status, function ($row) { return $row['Status'] == 'Stalled'; });
                if (count($stalled) > 0): 
                ?>
                    <ul class="client-list">
                        <?php foreach ($stalled as $client): ?>
                            <li>
                                <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                                <div>
                                    <span class="progress-badge" style="background-color: #FFA500;"><?php echo round($client['Progress'], 1); ?>%</span>
                                    <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-data">No stalled clients.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Canceled Clients -->
        <div id="canceled" class="report-section">
            <div class="report-container">
                <h3>Canceled Clients</h3>
                <?php 
                $canceled = array_filter($client_status, function ($row) { return $row['Status'] == 'Canceled'; });
                if (count($canceled) > 0): 
                ?>
                    <ul class="client-list">
                        <?php foreach ($canceled as $client): ?>
                            <li>
                                <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                                <div>
                                    <span class="progress-badge" style="background-color: #D3D3D3;"><?php echo round($client['Progress'], 1); ?>%</span>
                                    <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-data">No canceled clients.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Completed Until New Version Clients -->
        <?php if ($pending_count > 0): ?>
        <div id="completed-until-new-version" class="report-section">
            <div class="report-container">
                <h3>Pending New Version Release</h3>
                <?php 
                $completed_until_new = array_filter($client_status, function ($row) { return $row['Status'] == 'CompletedUntilNewVersion'; });
                ?>
                <ul class="client-list">
                    <?php foreach ($completed_until_new as $client): ?>
                        <li>
                            <span class="client-name"><?php echo htmlspecialchars($client['ClientName']); ?></span>
                            <div>
                                <span class="progress-badge" style="background-color: #ADD8E6;"><?php echo round($client['Progress'], 1); ?>%</span>
                                <span class="client-id"><?php echo htmlspecialchars($client['ClientID']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>