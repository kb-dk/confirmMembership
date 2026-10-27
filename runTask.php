<?php

/**
 * Run the Confirm Membership scheduled task
 */

// Get the correct path to OJS root
$ojsRoot = realpath(dirname(__FILE__) . '/../../..');

if (!$ojsRoot) {
    die("Error: Cannot find OJS root directory\n");
}

chdir($ojsRoot);

// Set up minimal environment
define('SESSION_DISABLE_INIT', true);

// OJS 3.5 bootstrap
require($ojsRoot . '/tools/bootstrap.php');

// Use the namespaced class
use APP\plugins\generic\confirmMembership\ConfirmMembershipTask;

// Load the plugin class file
$pluginPath = $ojsRoot . '/plugins/generic/confirmMembership/ConfirmMembershipPlugin.php';
if (file_exists($pluginPath)) {
    require_once($pluginPath);
} else {
    die("Error: Could not find ConfirmMembershipPlugin.php\n");
}

// Load the task class file
require_once($ojsRoot . '/plugins/generic/confirmMembership/ConfirmMembershipTask.php');

// Create plugin instance without registering it
$plugin = new \APP\plugins\generic\confirmMembership\ConfirmMembershipPlugin();

// Create and execute the task, passing the plugin instance
$task = new ConfirmMembershipTask($plugin);

echo "Starting Confirm Membership Task...\n";
echo "This task sends emails to users to confirm their membership.\n\n";

$result = $task->executeActions();

if ($result) {
    echo "\nTask completed successfully!\n";
} else {
    echo "\nTask failed!\n";
}

exit($result ? 0 : 1);
