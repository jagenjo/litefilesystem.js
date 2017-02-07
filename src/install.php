<?php 

	$allow_restart = true;

	//SAFETY: UNCOMMENT THE NEXT LINE TO BE SURE ITS IMPOSSIBLE TO LOSE THE DATA
	//$allow_restart = false; 

	$force = false;
	$restart_code = "YES";

	$console = php_sapi_name() == "cli";
	$end_string = $console ? "" : "</div></body></html>";

	// allows to reinstall from console
	if( $console && count($argv) > 1 && $argv[1] == "force" )
		$force = true;

	function showMessage($msg, $type = "danger")
	{
		global $console;

		if($console)
		{
			$msg = strip_tags($msg);
			echo ($type == "danger" ? "\033[31m - " : " + ") . $msg."\033[0m\n";
			return;
		}

		?>
		<div class="bs-callout bs-callout-<?=$type?>" id="callout-glyphicons-empty-only">
			<p><?=$msg?></p>
		</div>
		<?php
	}

	function getLog()
	{
		global $console;
		$result = "";

		$log = getDebugLog();
		foreach($log as $i => $line)
			if($console)
				$result .= "  .- ". $line."\n";
			else
				$result .= "<p>".$line."</p>";
		
		return $result;
	}

	register_shutdown_function( "fatal_handler" );

	function fatal_handler() {
		global $end_string;
		$errfile = "unknown file";
		$errstr  = "shutdown";
		$errno   = E_CORE_ERROR;
		$errline = 0;

		$error = error_get_last();
		if( $error !== NULL)
		{
			$errno   = $error["type"];
			$errfile = $error["file"];
			$errline = $error["line"];
			$errstr  = $error["message"];
			showMessage( "Code: " . $errno . " \"" . $errstr . "\" File: " . $errfile . " Ln: " . $errline );
			die($end_string);
		}
	}

	function read_input( $msg )
	{
		echo $msg;
		$handle = fopen ("php://stdin","r");
		$line = fgets($handle);
		return trim($line);
	}


if(!$console)
{
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
    <link rel="shortcut icon" href="assets/ico/favicon.ico">

    <title>LiteFileServer</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-callouts.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/style.css" rel="stylesheet">
	<link href="js/extra/ladda.min.css" rel="stylesheet" >
  </head>
  <body>
  <div class="container">
	<h1>LiteFileServer installer</h1>

<?php
}//cli

	if (!file_exists(__DIR__ . "/include/config.php"))
	{
		showMessage("Cannot find <strong>include/config.php</strong> file, please remember to create a copy of  <strong>config.sample.php</strong> as  <strong>config.php </strong> and edit it with your DB values.");
		die($end_string);
	}
	else
		showMessage("config.php found, testing database connection","success");

	//include core
	require_once 'include/core.php';
	loadModules("*");

	//check config has valid values
	if(ADMIN_PASS == "" || ADMIN_MAIL == "")
	{
		showMessage("Error: Config.php must be changed, edit the config.php and add a password and an email for the admin account.");
		die($end_string);
	}


	//check if config works
	$database = getSQLDB();
	if(!$database)
	{
		showMessage("Cannot connect to database, check that the info inside config.php is correct and your databse running.");
		die($end_string);
	}
	else
		showMessage("Database connection established","success");

	//check if there is data still
	$system = getModule("system");
	$is_ready = $system->checkReady();

	if( $is_ready && $console && count($argv) > 1 && $argv[1] == "upgrade" )
	{
		showMessage("Upgrading system...","primary");
		$system->upgradeSystem();
		showMessage("System upgraded","success");
		die($end_string);
	}

	//test folder owner
	$owner = posix_getgrgid( filegroup( __FILE__) );
	if($owner && $owner["name"] != "www-data")
	{
		showMessage("The group of this script is not 'www-data', this could be a problem. Ensure that all files inside this folder belong to the www-data by running this command from inside the folder: su chown -R :www-data *","danger");
	}

	if( $is_ready && !$force )
	{
		showMessage("All modules seem ready, nothing to do.","warning");
		die($end_string);
	}

	//RESTART
	if( ($force || !$is_ready))
	{
		if(!$allow_restart)
			showMessage("RESTART is blocked, allow_restart in install.php is set to false.","warning");
		else
		{
			clearDebugLog();
			showMessage("Creating databases and folders","primary");

			//Forcing the user prompt
			if($is_ready)
			{
				if($console)
				{
					$input = read_input("\033[1;33mYou are about to erase all the data and files inside LFS.\nIf you are sure type '".$restart_code."': \033[0m");
					if($input != $restart_code)
					{
						showMessage("Operation canceled.");
						die($end_string);
					}
					else
						showMessage("Database restart in course.");
				}
				else
				{
					showMessage("Complete DATABASE restart can only be performed from the console using: php install.php force");
					die($end_string);
				}
			}

			$system->restartSystem();

			//TEST SAVING ONE FILE
			//TEST RETRIEVING THAT FILE

			//READY
			if( $system->checkReady() )
				showMessage("LiteFileServer installed, you can go to the <a href='index.html'>main page</a>.","success");
			else
			{
				showMessage("Something went wrong.","warning");
				if(!$console) 
					echo('<div class="bs-callout bs-callout-<?=$type?>" id="callout-glyphicons-empty-only">' . getLog() . "</div>");
			}
		}
	}

if(!$console)
	echo $end_string;

?>