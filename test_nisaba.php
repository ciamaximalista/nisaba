<?php
// test_nisaba.php

// Set up the environment to simulate a web request
$_SERVER['REQUEST_METHOD'] = 'GET';

// Start a session and set the username
session_start();
$_SESSION['username'] = 'testuser';

// --- 1. Update Cache ---
echo "--- Running update_cache ---\\n";
$_GET['update_cache'] = '1';
$_GET['ajax'] = '1';
require 'nisaba.php';
unset($_GET['update_cache']);
unset($_GET['ajax']);

// --- 2. View Summary ---
echo "\\n--- Running nisaba_summary ---\\n";
$_GET['view'] = 'nisaba_summary';
require 'nisaba.php';

echo "\\nTest script finished.\\n";
?>