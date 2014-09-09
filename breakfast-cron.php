<?php

/** 
 * breakfast-cron, a php-cli based cron job to process and run rcon commands 
 * on minecraft servers, made for use with breakfast-donate
 **/

require __DIR__ . '/vendor/PHP-Source-Query-Class/SourceQuery/SourceQuery.class.php';
require 'vendor/autoload.php';

define("DONATION_MAX", 100);

function processDonations($messages) {
	
	// Returns percent of DONATION_MAX in recieved donations;
	
	$amount = 0;
	foreach($messages as $message) {
		$amount += $message->amount;
	}

	return round(($amount / DONATION_MAX) * 100);
}

function readJSONFile($file) {
	
	// Returns JSON object after reading it from file

	global $logger;

	if (file_exists($file)) {

		if (!$json = json_decode(file_get_contents($file))) {
			$logger->error('Unable to Open File: ' . $file);
			exit;
		}

	} else {

		$logger->error('Unable to find Path: ' . $file);
		exit;
	}

	return $json;
}


function sendCommand($server, $command){
	
	// Runs Rcon server commands
	// $server is an object containing server connection information
	// $command is a string containing the command to run
	global $logger;

	$sqTimeout = 1;
	$sqEngine = SourceQuery :: SOURCE;

	$query = new SourceQuery();



	try {
		$query->Connect($server->address, $server->port, $sqTimeout, $sqEngine);
		$query->SetRconPassword($server->password);
	} catch(Exception $e) {
		$logger->error('Could not connect to server. ERROR: ' . $e->getMessage);
	}

	$query->Rcon($command);

	$query->Disconnect();
}




$logger = new Katzgrau\KLogger\Logger(__DIR__ . '/logs');

// $breakfastDonatePath = "/var/www/html/wp-content/plugins/breakfast-donate/"
$breakfastDonatePath = '/var/www/breakfastcraft/wp-content/plugins/breakfast-donate/';

// Reading Breakfast Donate Config file to get filename for ipn message file
$bdConfig = $breakfastDonatePath . 'config.json';
$configJSON = readJSONFile($bdConfig);

if (isset($configJSON)) {
	$bdMessageFile = $breakfastDonatePath . $configJSON->message_file;
}

// Reading ipn messages and calculating donation level
$logger->info('Started donation processing');

$bdMessages = readJSONFile($bdMessageFile);

if (isset($bdMessages) && count($bdMessages > 0)) {
	$percent = processDonations($bdMessages);
} else {
	$logger->info('No donations to process :/. Exiting script.');
	exit;
}

//***************************
$percent = 57; //TESTING PURPOSES 
//***************************

if ($percent < 10) {
	$level = "00";
} elseif ($percent >= 100) {
	$level = "100";
} else {
	$level=(floor($percent/10)*10);
}


$serverList = readJSONFile('servers.json');
$permsFile = "{$level}_groups.yml";
$permsPath = __DIR__ . '/perms/' . $permsFile;
$linkError = false;

foreach ($serverList as $server) {

	$logger->info('Started processing for'. $server->name);
	
	$serverPath = $server->path . 'groups.yml';

	if (!is_link($serverPath)) {
	
		$isLinked = symlink($permsPath, $serverPath);

		if (!$isLinked) {
			$logger->error('New symlink creation failed for ' . $server->name);
			$linkError = true;
		}

	} elseif (is_link($serverPath) && readlink($serverPath) != $permsFile) {
		//Delete Old Symlink
		if (unlink($serverPath)) {
			if (!symlink($permsPath, $serverPath)) {
				$logger->error('Symlink update failed for ' . $server->name);
				$linkError = true;
			}
		} else {
			$logger->error('Unable to delete symlink for ' . $server->name);
			$linkError = true;
		}
	} else {
		$logger->info('No changes needed for ' . $server->name);
	}

	$reloadPerms = 'manload';
	$serverMessage = 'say Server donation level increased. Enjoy your new permissions!';
	
	if (!$linkError) {
		$logger->info('Running server commands for ' .  $server->name);
		sendCommand($server, $reloadPerms);
		sendCommand($server, $serverMessage);
	} 

}

$logger->info('Donation processing complete');