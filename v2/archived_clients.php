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
$clients = [];

if ($archiveTableExists) {
    $yearsResult = $conn->query("SELECT DISTINCT ArchiveYear FROM OnboardingArchive ORDER BY ArchiveYear DESC");
    while ($row = $yearsResult->fetch_assoc()) {
        $years[] = (int) $row['ArchiveYear'];
    }

    if (!empty($years)) {
        $selected_year = isset($_GET['year']) ? (int) $_GET['year'] : $years[0];

        $sql = "SELECT o.ClientID, o.ClientName, o.AssignedTech, o.SalesRep, o.PhoneNumber, o.Email, o.Progress, o.Completed, o.ArchiveYear, o.ArchivedAt,
                       u.FirstName AS TechFirstName, u.LastName AS TechLastName
                FROM OnboardingArchive o
                LEFT JOIN Users u ON CAST(o.AssignedTech AS CHAR) = CAST(u.UserID AS CHAR)
                WHERE o.ArchiveYear = ?
                ORDER BY o.ClientName";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Clients</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body class="page-archived-clients">
<?php include 'includes/header.php'; ?>
<div class="archive-container">
    <h2>Archived Clients by Year</h2>

    <div class="archive-controls">
        <a href="settings.php" class="btn-secondary">← Back to Settings</a>
        <?php if ($archiveTableExists && !empty($years)): ?>
            <form method="GET" action="" class="year-form">
                <label for="year">Archive Year</label>
                <select id="year" name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo $selected_year === $year ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$archiveTableExists): ?>
        <div class="empty-state">No archive data found yet. Run Year Rollover from Settings to create archives.</div>
    <?php elseif (empty($years)): ?>
        <div class="empty-state">No archived years available yet.</div>
    <?php elseif (empty($clients)): ?>
        <div class="empty-state">No clients found for archive year <?php echo htmlspecialchars((string) $selected_year); ?>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="archive-table">
                <thead>
                    <tr>
                        <th>Client ID</th>
                        <th>Client Name</th>
                        <th>Assigned Tech</th>
                        <th>Sales Rep</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Progress</th>
                        <th>Completed</th>
                        <th>Archived At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($client['ClientID']); ?></td>
                            <td><?php echo htmlspecialchars($client['ClientName']); ?></td>
                            <td>
                                <?php
                                $techName = trim((string) (($client['TechFirstName'] ?? '') . ' ' . ($client['TechLastName'] ?? '')));
                                echo htmlspecialchars($techName !== '' ? $techName : (string) $client['AssignedTech']);
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($client['SalesRep']); ?></td>
                            <td><?php echo htmlspecialchars($client['PhoneNumber']); ?></td>
                            <td><?php echo htmlspecialchars($client['Email']); ?></td>
                            <td><?php echo (int) round((float) $client['Progress']); ?>%</td>
                            <td><?php echo (int) $client['Completed'] === 1 ? 'Yes' : 'No'; ?></td>
                            <td><?php echo htmlspecialchars($client['ArchivedAt']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
