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

$client_id = isset($_GET['client_id']) ? trim((string) $_GET['client_id']) : '';
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;

if ($client_id === '' || $selected_year <= 0) {
    echo "Invalid archive client request.";
    exit;
}

$onboarding = null;
$details = null;
$history = [];
$programs = null;

$stmt = $conn->prepare("SELECT o.*, u.FirstName AS TechFirstName, u.LastName AS TechLastName
                        FROM OnboardingArchive o
                        LEFT JOIN Users u ON CAST(o.AssignedTech AS CHAR) = CAST(u.UserID AS CHAR)
                        WHERE o.ClientID = ? AND o.ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$onboarding = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$onboarding) {
    echo "Archived client not found.";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM OnboardingDetailsArchive WHERE ClientID = ? AND ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM OnboardingHistoryArchive WHERE ClientID = ? AND ArchiveYear = ? ORDER BY Date DESC, HistoryID DESC");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$historyRes = $stmt->get_result();
while ($row = $historyRes->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM EntitledProgramsArchive WHERE ClientID = ? AND ArchiveYear = ? LIMIT 1");
$stmt->bind_param('si', $client_id, $selected_year);
$stmt->execute();
$programs = $stmt->get_result()->fetch_assoc();
$stmt->close();

$techName = trim((string) (($onboarding['TechFirstName'] ?? '') . ' ' . ($onboarding['TechLastName'] ?? '')));
if ($techName === '') {
    $techName = (string) ($onboarding['AssignedTech'] ?? 'Unassigned');
}

$programList = [];
if (is_array($programs)) {
    foreach ($programs as $column => $value) {
        if (in_array($column, ['ProgramID', 'ClientID', 'ArchiveYear', 'ArchivedAt'], true)) {
            continue;
        }
        if ((int) $value === 1) {
            $programList[] = $column;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archived Client Details</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body class="page-archived-onboarding-detail">
<?php include 'includes/header.php'; ?>

<div class="archive-detail-container">
    <div class="archive-controls">
        <a class="btn-secondary" href="archived_dashboard.php?year=<?php echo urlencode((string) $selected_year); ?>">← Back to Archived Dashboard</a>
        <a class="btn-secondary" href="archived_clients.php?year=<?php echo urlencode((string) $selected_year); ?>">Back to Archived Clients</a>
    </div>

    <h2>Archived Onboarding Details (<?php echo htmlspecialchars((string) $selected_year); ?>)</h2>

    <div class="archive-summary-grid">
        <div><strong>Client ID:</strong> <?php echo htmlspecialchars((string) $onboarding['ClientID']); ?></div>
        <div><strong>Client Name:</strong> <?php echo htmlspecialchars((string) $onboarding['ClientName']); ?></div>
        <div><strong>Assigned Tech:</strong> <?php echo htmlspecialchars($techName); ?></div>
        <div><strong>Sales Rep:</strong> <?php echo htmlspecialchars((string) ($onboarding['SalesRep'] ?? '')); ?></div>
        <div><strong>Phone:</strong> <?php echo htmlspecialchars((string) ($onboarding['PhoneNumber'] ?? '')); ?></div>
        <div><strong>Email:</strong> <?php echo htmlspecialchars((string) ($onboarding['Email'] ?? '')); ?></div>
        <div><strong>Progress:</strong> <?php echo (int) round((float) ($onboarding['Progress'] ?? 0)); ?>%</div>
        <div><strong>Completed:</strong> <?php echo ((int) ($onboarding['Completed'] ?? 0) === 1) ? 'Yes' : 'No'; ?></div>
    </div>

    <div class="archive-panel">
        <h3>Checklist Notes</h3>
        <p><strong>First Callout:</strong> <?php echo htmlspecialchars((string) ($details['FirstCallout'] ?? '')); ?></p>
        <p><strong>Follow Up Calls:</strong> <?php echo htmlspecialchars((string) ($details['FollowUpCalls'] ?? '')); ?></p>
        <p><strong>Internal Notes:</strong> <?php echo nl2br(htmlspecialchars((string) ($details['Notes'] ?? ''))); ?></p>
    </div>

    <div class="archive-panel">
        <h3>Entitled Programs</h3>
        <?php if (empty($programList)): ?>
            <p>No entitled programs were saved for this archived client.</p>
        <?php else: ?>
            <ul class="archive-program-list">
                <?php foreach ($programList as $program): ?>
                    <li><?php echo htmlspecialchars((string) $program); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="archive-panel">
        <h3>Archived History</h3>
        <?php if (empty($history)): ?>
            <p>No archived history found.</p>
        <?php else: ?>
            <table class="archive-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Edited By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($item['Date'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['ActionType'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['ActionDetails'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($item['EditedBy'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
