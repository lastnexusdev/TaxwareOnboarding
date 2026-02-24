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

if ($archiveTableExists) {
    $yearsResult = $conn->query("SELECT DISTINCT ArchiveYear FROM OnboardingArchive ORDER BY ArchiveYear DESC");
    while ($row = $yearsResult->fetch_assoc()) {
        $years[] = (int) $row['ArchiveYear'];
    }

    if (!empty($years)) {
        $selected_year = isset($_GET['year']) ? (int) $_GET['year'] : $years[0];

        $sql = "SELECT o.ClientID, o.ClientName, o.PhoneNumber, o.Progress, o.Completed, o.SalesRep, o.Package, o.AssignedTech,
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
    <title>Archived Year Dashboard</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script>
        function openArchivedClient(clientId, year) {
            window.location.href = 'archived_onboarding_detail.php?client_id=' + encodeURIComponent(clientId) + '&year=' + encodeURIComponent(year);
        }
    </script>
</head>
<body class="page-archived-dashboard">
<?php include 'includes/header.php'; ?>

<div class="archive-dashboard-container">
    <h2>Archived Dashboard</h2>

    <?php if (!$archiveTableExists): ?>
        <div class="empty-state">No archive data found yet. Run Year Rollover from Settings first.</div>
    <?php elseif (empty($years)): ?>
        <div class="empty-state">No archived years available yet.</div>
    <?php else: ?>
        <div class="archive-controls">
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

        <?php if (empty($techBuckets)): ?>
            <div class="empty-state">No clients found for archive year <?php echo htmlspecialchars((string)$selected_year); ?>.</div>
        <?php else: ?>
            <?php foreach ($techBuckets as $techName => $clients): ?>
                <div class="tech-box">
                    <div class="tech-header">
                        <h3><?php echo htmlspecialchars($techName); ?></h3>
                        <span class="tech-stat"><?php echo count($clients); ?> clients</span>
                    </div>
                    <table class="tech-table">
                        <thead>
                            <tr>
                                <th>Client ID</th>
                                <th>Client Name</th>
                                <th>Phone</th>
                                <th>Progress</th>
                                <th>Completed</th>
                                <th>Sales Rep</th>
                                <th>Package</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr onclick="openArchivedClient('<?php echo htmlspecialchars($client['ClientID']); ?>', '<?php echo htmlspecialchars((string)$selected_year); ?>')">
                                    <td><strong><?php echo htmlspecialchars($client['ClientID']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($client['ClientName']); ?></td>
                                    <td><?php echo htmlspecialchars($client['PhoneNumber']); ?></td>
                                    <td><?php echo (int) round((float)$client['Progress']); ?>%</td>
                                    <td><?php echo (int)$client['Completed'] === 1 ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                                    <td><?php echo htmlspecialchars($client['Package']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
