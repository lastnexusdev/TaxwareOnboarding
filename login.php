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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background-color: #f5f5f5;
            background-image: url('https://kb.taxwaresystems.com/web_texture_mirrored.svg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Bar */
        .header-bar {
            background-color: #a43c3c;
            padding: 15px 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .header-bar img {
            height: 50px;
            width: auto;
        }

        /* Main Content Area */
        .login-page-content {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        /* Login Container */
        .login-wrapper {
            width: 100%;
            max-width: 500px;
        }

        .login-container {
            background: #fff;
            padding: 50px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            text-align: center;
        }

        .login-header {
            margin-bottom: 35px;
        }

        .login-header h2 {
            color: #333;
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .login-header p {
            color: #666;
            font-size: 15px;
        }

        /* Error Message */
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 6px;
            border-left: 4px solid #a43c3c;
            margin-bottom: 25px;
            font-size: 14px;
            text-align: left;
        }

        /* Form Styling */
        form {
            text-align: left;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s ease;
            background-color: #fff;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="password"]:focus {
            outline: none;
            border-color: #a43c3c;
            box-shadow: 0 0 0 3px rgba(164, 60, 60, 0.1);
        }

        .form-group input[type="text"]:hover,
        .form-group input[type="password"]:hover {
            border-color: #999;
        }

        /* Show Password Checkbox */
        .show-password {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 25px;
        }

        .show-password input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #a43c3c;
        }

        .show-password label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
            color: #555;
            font-size: 14px;
            user-select: none;
        }

        /* Login Button */
        .btn-login {
            width: 100%;
            padding: 14px;
            background-color: #a43c3c;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            background-color: #8a3232;
            box-shadow: 0 4px 12px rgba(164, 60, 60, 0.3);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .btn-login:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }

        /* Footer */
        .login-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e1e8ed;
            text-align: center;
        }

        .login-footer p {
            color: #666;
            font-size: 13px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-bar {
                padding: 12px 20px;
            }

            .header-bar img {
                height: 40px;
            }

            .login-container {
                padding: 40px 30px;
            }

            .login-header h2 {
                font-size: 22px;
            }
        }

        @media (max-width: 480px) {
            .login-page-content {
                padding: 20px 15px;
            }

            .login-container {
                padding: 30px 20px;
            }

            .form-group input[type="text"],
            .form-group input[type="password"] {
                padding: 10px 12px;
            }
        }

        /* Loading State */
        .btn-login.loading {
            pointer-events: none;
            opacity: 0.7;
            position: relative;
        }

        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            right: 20px;
            margin-top: -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spinner 0.6s linear infinite;
        }

        @keyframes spinner {
            to { transform: rotate(360deg); }
        }

        /* Accessibility */
        *:focus-visible {
            outline: 2px solid #a43c3c;
            outline-offset: 2px;
        }
    </style>
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