<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = $currentPage ?? '';
$department = (int) ($_SESSION['department'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');
?>

<div class="top-menu">
    <a href="dashboard.php" class="top-menu-logo" aria-label="Taxware Dashboard Home">
        <img src="https://kb.taxwaresystems.com/logo.png" alt="Taxware Systems">
    </a>
    <ul>
        <li>
            <a href="dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                Dashboard
            </a>
        </li>

        <?php if ($department === 1 || $role === 'admin'): ?>
            <li>
                <a href="sales.php" class="<?= $currentPage === 'sales' ? 'active' : '' ?>">
                    Add Client
                </a>
            </li>

            <li>
                <a href="remove_client.php" class="<?= $currentPage === 'remove' ? 'active' : '' ?>">
                    Remove Client
                </a>
            </li>

            <li>
                <a href="edit_client.php" class="<?= $currentPage === 'edit' ? 'active' : '' ?>">
                    Edit Clients
                </a>
            </li>

            <li>
                <a href="settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                    Settings
                </a>
            </li>

            <li>
                <a href="reports.php" class="<?= $currentPage === 'reports' ? 'active' : '' ?>">
                    Reports
                </a>
            </li>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <li>
                <a href="users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">
                    Manage Users
                </a>
            </li>
        <?php endif; ?>

        <li>
            <a href="logout.php">Logout</a>
        </li>
    </ul>
</div>
