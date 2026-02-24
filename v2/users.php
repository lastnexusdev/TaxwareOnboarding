<?php
session_start();
require_once "auth_check.php";
require_once "db.php";

$currentPage = 'users';

// Check if the user is logged in and is an admin
if (!isset($_SESSION['userid']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    echo "Access denied. Admin privileges required.";
    exit;
}

// Handle user actions
$message = '';
$error = '';

// Add new user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $department = intval($_POST['department']);
    $spanish = isset($_POST['spanish']) ? 1 : 0;
    
    // Check if username already exists
    $check_sql = "SELECT UserID FROM Users WHERE Username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('s', $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Username already exists. Please choose a different username.";
    } else {
        // Insert with default values for timecard fields
        $insert_sql = "INSERT INTO Users (Username, Password, FirstName, LastName, Email, Role, Department, Spanish, StartHour, EndHour) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '08:00', '17:00')";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param('ssssssii', $username, $password, $first_name, $last_name, $email, $role, $department, $spanish);
        
        if ($insert_stmt->execute()) {
            $message = "User added successfully!";
        } else {
            $error = "Error adding user: " . $conn->error;
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}

// Update user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = trim($_POST['edit_username']);
    $first_name = trim($_POST['edit_first_name']);
    $last_name = trim($_POST['edit_last_name']);
    $email = trim($_POST['edit_email']);
    $role = $_POST['edit_role'];
    $department = intval($_POST['edit_department']);
    $spanish = isset($_POST['edit_spanish']) ? 1 : 0;
    
    // Update password only if provided
    if (!empty($_POST['edit_password'])) {
        $password = password_hash($_POST['edit_password'], PASSWORD_DEFAULT);
        $update_sql = "UPDATE Users SET Username = ?, Password = ?, FirstName = ?, LastName = ?, Email = ?, Role = ?, Department = ?, Spanish = ? WHERE UserID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('ssssssiis', $username, $password, $first_name, $last_name, $email, $role, $department, $spanish, $user_id);
    } else {
        $update_sql = "UPDATE Users SET Username = ?, FirstName = ?, LastName = ?, Email = ?, Role = ?, Department = ?, Spanish = ? WHERE UserID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('sssssiis', $username, $first_name, $last_name, $email, $role, $department, $spanish, $user_id);
    }
    
    if ($update_stmt->execute()) {
        $message = "User updated successfully!";
    } else {
        $error = "Error updating user: " . $conn->error;
    }
    $update_stmt->close();
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['delete_user_id']);
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['userid']) {
        $error = "You cannot delete your own account!";
    } else {
        // Check if user has assigned clients
        $check_clients_sql = "SELECT COUNT(*) as client_count FROM Onboarding WHERE AssignedTech = ?";
        $check_stmt = $conn->prepare($check_clients_sql);
        $check_stmt->bind_param('i', $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['client_count'] > 0) {
            $error = "Cannot delete user with assigned clients. Please reassign clients first.";
        } else {
            $delete_sql = "DELETE FROM Users WHERE UserID = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $user_id);
            
            if ($delete_stmt->execute()) {
                $message = "User deleted successfully!";
            } else {
                $error = "Error deleting user: " . $conn->error;
            }
            $delete_stmt->close();
        }
        $check_stmt->close();
    }
}

// Fetch all users with assigned client counts
$users_sql = "SELECT u.*, 
              (SELECT COUNT(*) FROM Onboarding WHERE AssignedTech = u.UserID) as assigned_clients
              FROM Users u 
              ORDER BY u.Department, u.FirstName, u.LastName";
$users_result = $conn->query($users_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" type="text/css" href="../style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
    <div class="user-management-container">
        <h2>User Management</h2>
        
        <?php 
        // Calculate stats
        $total_users = 0;
        $sales_count = 0;
        $tech_count = 0;
        $spanish_count = 0;
        
        $stats_sql = "SELECT Department, Spanish, COUNT(*) as count FROM Users GROUP BY Department, Spanish";
        $stats_result = $conn->query($stats_sql);
        while ($stat = $stats_result->fetch_assoc()) {
            $total_users += $stat['count'];
            if ($stat['Department'] == 1) $sales_count += $stat['count'];
            if ($stat['Department'] == 2) $tech_count += $stat['count'];
            if ($stat['Spanish'] == 1) $spanish_count += $stat['count'];
        }
        ?>
        
        <div class="stats-summary">
            <div class="stat-card">
                <h4>Total Users</h4>
                <p class="number"><?php echo $total_users; ?></p>
            </div>
            <div class="stat-card sales">
                <h4>Sales Department</h4>
                <p class="number"><?php echo $sales_count; ?></p>
            </div>
            <div class="stat-card tech">
                <h4>Tech Department</h4>
                <p class="number"><?php echo $tech_count; ?></p>
            </div>
            <div class="stat-card spanish">
                <h4>Spanish Speakers</h4>
                <p class="number"><?php echo $spanish_count; ?></p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Add User Section -->
        <div class="add-user-section">
            <h3>Add New User</h3>
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required minlength="3">
                        <small>Minimum 3 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="6" onkeyup="checkPasswordStrength('password', 'password-strength')">
                        <span id="password-strength" class="password-strength"></span>
                    </div>
                    
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role *</label>
                        <select id="role" name="role" required>
                            <option value="user">User</option>
                            <option value="sales">Sales</option>
                            <option value="admin">Admin</option>
                        </select>
                        <small>Admin: Full access | Sales: Add/remove clients | User: View only</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department *</label>
                        <select id="department" name="department" required>
                            <option value="1">Sales (1)</option>
                            <option value="2">Tech (2)</option>
                        </select>
                        <small>Sales: Client acquisition | Tech: Onboarding & support</small>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="spanish" name="spanish" value="1">
                    <label for="spanish">Spanish Speaking (for client assignment)</label>
                </div>
                
                <button type="submit" name="add_user" class="btn btn-primary" style="margin-top: 20px;">Add User</button>
            </form>
        </div>
        
        <!-- Users Table Section -->
        <div class="users-table-section">
            <h3>Existing Users</h3>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Spanish</th>
                        <th>Assigned Clients</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($user = $users_result->fetch_assoc()): ?>
                        <tr class="<?php echo $user['Department'] == 1 ? 'dept-1' : 'dept-2'; ?>">
                            <td><?php echo htmlspecialchars($user['UserID']); ?></td>
                            <td><?php echo htmlspecialchars($user['Username']); ?></td>
                            <td><?php echo htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']); ?></td>
                            <td><?php echo htmlspecialchars($user['Email']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['Role'] == 'admin' ? 'badge-admin' : ($user['Role'] == 'sales' ? 'badge-sales' : 'badge-user'); ?>">
                                    <?php echo strtoupper($user['Role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    echo $user['Department'] == 1 ? 'Sales' : 'Tech';
                                    echo ' (' . $user['Department'] . ')';
                                ?>
                            </td>
                            <td><?php echo $user['Spanish'] ? '✓' : '✗'; ?></td>
                            <td>
                                <?php if ($user['assigned_clients'] > 0): ?>
                                    <span class="client-count"><?php echo $user['assigned_clients']; ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-edit" onclick='openEditModal(<?php echo json_encode($user); ?>)'>Edit</button>
                                    <?php if ($user['UserID'] != $_SESSION['userid']): ?>
                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?<?php echo $user['assigned_clients'] > 0 ? ' This user has ' . $user['assigned_clients'] . ' assigned clients.' : ''; ?>');">
                                            <input type="hidden" name="delete_user_id" value="<?php echo $user['UserID']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-delete">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h3>Edit User</h3>
            <form method="POST" action="" onsubmit="return validateEditForm()">
                <input type="hidden" id="edit_user_id" name="user_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="edit_username" required minlength="3">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_password">Password</label>
                        <input type="password" id="edit_password" name="edit_password" minlength="6" onkeyup="checkPasswordStrength('edit_password', 'edit-password-strength')">
                        <small>Leave blank to keep current password</small>
                        <span id="edit-password-strength" class="password-strength"></span>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_first_name">First Name *</label>
                        <input type="text" id="edit_first_name" name="edit_first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_last_name">Last Name *</label>
                        <input type="text" id="edit_last_name" name="edit_last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" id="edit_email" name="edit_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_role">Role *</label>
                        <select id="edit_role" name="edit_role" required>
                            <option value="user">User</option>
                            <option value="sales">Sales</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_department">Department *</label>
                        <select id="edit_department" name="edit_department" required>
                            <option value="1">Sales (1)</option>
                            <option value="2">Tech (2)</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="edit_spanish" name="edit_spanish" value="1">
                    <label for="edit_spanish">Spanish Speaking</label>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function checkPasswordStrength(inputId, strengthId) {
            const password = document.getElementById(inputId).value;
            const strengthSpan = document.getElementById(strengthId);
            
            if (password.length === 0) {
                strengthSpan.textContent = '';
                return;
            }
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            if (strength <= 2) {
                strengthSpan.textContent = 'Weak password';
                strengthSpan.className = 'password-strength password-weak';
            } else if (strength <= 4) {
                strengthSpan.textContent = 'Medium password';
                strengthSpan.className = 'password-strength password-medium';
            } else {
                strengthSpan.textContent = 'Strong password';
                strengthSpan.className = 'password-strength password-strong';
            }
        }

        function validateAddForm() {
            const password = document.getElementById('password').value;
            if (password.length < 6) {
                alert('Password must be at least 6 characters long');
                return false;
            }
            return true;
        }

        function validateEditForm() {
            const password = document.getElementById('edit_password').value;
            if (password !== '' && password.length < 6) {
                alert('Password must be at least 6 characters long');
                return false;
            }
            return true;
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.UserID;
            document.getElementById('edit_username').value = user.Username;
            document.getElementById('edit_first_name').value = user.FirstName;
            document.getElementById('edit_last_name').value = user.LastName;
            document.getElementById('edit_email').value = user.Email;
            document.getElementById('edit_role').value = user.Role;
            document.getElementById('edit_department').value = user.Department;
            document.getElementById('edit_spanish').checked = user.Spanish == 1;
            
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit-password-strength').textContent = '';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>