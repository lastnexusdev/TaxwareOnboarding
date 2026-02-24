<?php
session_start();
require_once "db.php"; // your DB connection

// Already logged in? redirect
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? "");
    $password = trim($_POST['password'] ?? "");

    if ($username !== "" && $password !== "") {
        $stmt = $conn->prepare("SELECT UserID, Username, Password, Role, Department FROM Users WHERE Username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $db_username, $db_password, $role, $department);
            $stmt->fetch();

            if (password_verify($password, $db_password)) {
                // Session variables
                $_SESSION['user_id']    = $user_id;
                $_SESSION['userid']     = $user_id;  // legacy name for existing code
                $_SESSION['username']   = $db_username;
                $_SESSION['role']       = $role;
                $_SESSION['department'] = $department;

                // Redirect based on role/department
                if ($role === 'admin') {
                    header("Location: dashboard.php");
                } elseif ($role === 'sales' || $department == 1) {
                    header("Location: sales.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "Invalid username.";
        }
        $stmt->close();
    } else {
        $error = "Please enter both username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Taxware Systems</title>
    <link rel="stylesheet" href="styles.css">
        <link rel="stylesheet" type="text/css" href="css/login.css">
</head>
<body>
    <!-- Header Bar -->
    <div class="header-bar">
        <img src="https://kb.taxwaresystems.com/logo.png" alt="Taxware Systems">
    </div>

    <!-- Main Content -->
    <div class="login-page-content">
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-header">
                    <h2>Welcome Back</h2>
                    <p>Client Onboarding System</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            required 
                            autofocus
                            autocomplete="username"
                            placeholder="Enter your username"
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >
                    </div>

                    <div class="show-password">
                        <input type="checkbox" id="showPassword">
                        <label for="showPassword">Show Password</label>
                    </div>

                    <button type="submit" class="btn-login" id="loginBtn">
                        Sign In
                    </button>
                </form>

                <div class="login-footer">
                    <p>&copy; <?php echo date('Y'); ?> Taxware Systems, Inc. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const passwordInput = document.getElementById("password");
        const showPassword = document.getElementById("showPassword");
        const loginForm = document.getElementById("loginForm");
        const loginBtn = document.getElementById("loginBtn");

        showPassword.addEventListener("change", function () {
            passwordInput.type = this.checked ? "text" : "password";
        });

        // Add loading state to button on submit
        loginForm.addEventListener("submit", function() {
            loginBtn.classList.add("loading");
            loginBtn.disabled = true;
        });

        // Prevent multiple submissions
        let isSubmitting = false;
        loginForm.addEventListener("submit", function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
        });
    </script>
</body>
</html>