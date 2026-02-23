<?php
session_start();
$_SESSION = [];          // clear variables
session_destroy();       // destroy session
header("Location: login.php");
exit;
