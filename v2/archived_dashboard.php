<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'settings';

if (!isset($_SESSION['userid']) ||
    ((!isset($_SESSION['department']) || $_SESSION['department'] != 1) &&
    (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin'))) {
    echo "Access denied.";
    exit;
}

$archiveTableExists = false;
$checkTable = $conn->query("SHOW TABLES LIKE 'OnboardingArchive'");
if ($checkTable && $checkTable->num_rows > 0) {
    $archiveTableExists = true;
}

$years = [];
$selected_year = null;
$techBuckets = [];
$stats = [
    'total_clients' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'not_started' => 0,
    'stalled' => 0,
    'cancelled' => 0
];

if ($archiveTableExists) {
    $yearsResult = $conn->query("SELECT DISTINCT ArchiveYear FROM OnboardingArchive ORDER BY ArchiveYear DESC");
    while ($row = $yearsResult->fetch_assoc()) {
        $years[] = (int) $row['ArchiveYear'];
    }

    if (!empty($years)) {
        $selected_year = isset($_GET['year']) ? (int) $_GET['year'] : $years[0];

        $statsSql = "SELECT
                        COUNT(*) as total_clients,
                        SUM(CASE WHEN Progress >= 100 THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN Progress > 0 AND Progress < 100 THEN 1 ELSE 0 END) as in_progress,
                        SUM(CASE WHEN Progress <= 0 THEN 1 ELSE 0 END) as not_started,
                        SUM(CASE WHEN Stalled = 1 THEN 1 ELSE 0 END) as stalled,
                        SUM(CASE WHEN Cancelled = 1 THEN 1 ELSE 0 END) as cancelled
                    FROM OnboardingArchive
                    WHERE ArchiveYear = ?";
        $statsStmt = $conn->prepare($statsSql);
        $statsStmt->bind_param('i', $selected_year);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result()->fetch_assoc();
        if ($statsResult) {
            $stats = array_merge($stats, $statsResult);
        }
        $statsStmt->close();

        $sql = "SELECT o.ClientID, o.ClientName, o.PhoneNumber, o.Progress, o.Completed, o.SalesRep, o.Package,
                       o.ConvertionNeeded AS ConversionStatus, o.Stalled, o.Cancelled, o.AssignedTech,
                       u.FirstName, u.LastName
                FROM OnboardingArchive o
                LEFT JOIN Users u ON CAST(o.AssignedTech AS CHAR) = CAST(u.UserID AS CHAR)
                WHERE o.ArchiveYear = ?
                ORDER BY u.FirstName, u.LastName, o.ClientName";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $selected_year);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $techName = trim((string) (($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')));
            if ($techName === '') {
                $techName = 'Unassigned / Unknown Tech';
            }
            if (!isset($techBuckets[$techName])) {
                $techBuckets[$techName] = [];
            }
            $techBuckets[$techName][] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Tech Dashboard</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script>
        function openArchivedClient(clientId, year) {
            window.location.href = 'archived_onboarding_detail.php?client_id=' + encodeURIComponent(clientId) + '&year=' + encodeURIComponent(year);
        }
    </script>
</head>
<body class="page-dashboard page-archived-dashboard">
<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <h2>Archived Tech Dashboard (<?php echo htmlspecialchars((string) $selected_year); ?>)</h2>

    <?php if (!$archiveTableExists): ?>
        <div class="empty-state">No archive data found yet. Run Year Rollover from Settings first.</div>
    <?php elseif (empty($years)): ?>
        <div class="empty-state">No archived years available yet.</div>
    <?php else: ?>
        <div class="archive-controls" style="margin-bottom: 20px;">
            <a class="btn-secondary" href="archived_clients.php?year=<?php echo urlencode((string) $selected_year); ?>">← Back to Archived Clients</a>
            <form method="GET" action="" class="year-form">
                <label for="year">Archive Year</label>
                <select id="year" name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo $selected_year === $year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="stats-overview">
            <div class="stat-card total">
                <h4>Total Clients</h4>
                <div class="number"><?php echo (int) $stats['total_clients']; ?></div>
                <div class="percentage">Archived for <?php echo htmlspecialchars((string) $selected_year); ?></div>
            </div>
            <div class="stat-card completed">
                <h4>Completed</h4>
                <div class="number"><?php echo (int) $stats['completed']; ?></div>
                <div class="percentage"><?php echo (int) ((int)$stats['total_clients'] > 0 ? round(($stats['completed'] / $stats['total_clients']) * 100) : 0); ?>% of total</div>
            </div>
            <div class="stat-card in-progress">
                <h4>In Progress</h4>
                <div class="number"><?php echo (int) $stats['in_progress']; ?></div>
                <div class="percentage"><?php echo (int) ((int)$stats['total_clients'] > 0 ? round(($stats['in_progress'] / $stats['total_clients']) * 100) : 0); ?>% of total</div>
            </div>
            <div class="stat-card not-started">
                <h4>Not Started</h4>
                <div class="number"><?php echo (int) $stats['not_started']; ?></div>
                <div class="percentage"><?php echo (int) ((int)$stats['total_clients'] > 0 ? round(($stats['not_started'] / $stats['total_clients']) * 100) : 0); ?>% of total</div>
            </div>
            <div class="stat-card stalled">
                <h4>Stalled</h4>
                <div class="number"><?php echo (int) $stats['stalled']; ?></div>
                <div class="percentage">Needs attention</div>
            </div>
            <div class="stat-card cancelled">
                <h4>Cancelled</h4>
                <div class="number"><?php echo (int) $stats['cancelled']; ?></div>
                <div class="percentage">Inactive</div>
            </div>
        </div>

        <div class="legend">
            <h4>Status Legend:</h4>
            <div class="legend-items">
                <div class="legend-item"><div class="legend-color" style="background-color: #D3D3D3;"></div><span class="legend-label">Cancelled</span></div>
                <div class="legend-item"><div class="legend-color" style="background-color: #32CD32;"></div><span class="legend-label">Completed</span></div>
                <div class="legend-item"><div class="legend-color" style="background-color: #FFD700;"></div><span class="legend-label">In Progress</span></div>
                <div class="legend-item"><div class="legend-color" style="background-color: #FFB5B3;"></div><span class="legend-label">Stalled</span></div>
            </div>
        </div>

        <div class="tech-container">
            <?php if (empty($techBuckets)): ?>
                <div class="empty-state">No clients found for archive year <?php echo htmlspecialchars((string) $selected_year); ?>.</div>
            <?php else: ?>
                <?php foreach ($techBuckets as $techName => $clients): ?>
                    <?php
                    $client_count = count($clients);
                    $completed_count = 0;
                    $in_progress_count = 0;
                    foreach ($clients as $clientRow) {
                        if ((float) $clientRow['Progress'] >= 100) {
                            $completed_count++;
                        } elseif ((float) $clientRow['Progress'] > 0) {
                            $in_progress_count++;
                        }
                    }
                    ?>
                    <div class="tech-box">
                        <div class="tech-header">
                            <h3><?php echo htmlspecialchars($techName); ?></h3>
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
                                <?php foreach ($clients as $client): ?>
                                    <?php
                                    $progress = (float) ($client['Progress'] ?? 0);
                                    $row_color = '';
                                    if (!empty($client['Cancelled'])) {
                                        $row_color = '#D3D3D3';
                                    } elseif ($progress >= 100) {
                                        $row_color = '#32CD32';
                                    } elseif (!empty($client['Stalled'])) {
                                        $row_color = '#FFB5B3';
                                    } elseif ($progress > 0) {
                                        $row_color = '#FFD700';
                                    }
                                    ?>
                                    <tr style="background-color: <?php echo htmlspecialchars($row_color); ?>" onclick="openArchivedClient('<?php echo htmlspecialchars((string) $client['ClientID']); ?>', '<?php echo htmlspecialchars((string) $selected_year); ?>')">
                                        <td><strong><?php echo htmlspecialchars((string) $client['ClientID']); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string) $client['ClientName']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $client['PhoneNumber']); ?></td>
                                        <td>
                                            <div class="progress-cell">
                                                <div class="mini-progress">
                                                    <div class="mini-progress-fill" style="width: <?php echo (int) round($progress); ?>%;"></div>
                                                </div>
                                                <span><?php echo (int) round($progress); ?>%</span>
                                            </div>
                                        </td>
                                        <td><?php echo ((int) ($client['Completed'] ?? 0) === 1) ? 'Yes' : 'No'; ?></td>
                                        <td><?php echo htmlspecialchars((string) ($client['ConversionStatus'] ?? 'No')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($client['SalesRep'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($client['Package'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
