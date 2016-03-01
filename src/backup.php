<?php 

	$force = false;
	$console = php_sapi_name() == "cli";
	$end_string = $console ? "" : "</div></body></html>";

	if(!$console)
		die($end_string);

	function showMessage($msg, $type = "danger")
	{
		global $console;

		if($console)
		{
			$msg = strip_tags($msg);
			echo ($type == "danger" ? "\033[31m - " : "") . $msg."\033[0m\n";
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
	<h1>LiteFileServer Backup tool</h1>

<?php
}

	showMessage(" LFS Backup tool *************","success");

	if (!file_exists(__DIR__ . "/include/config.php"))
	{
		showMessage("Cannot find <strong>include/config.php</strong> file, please remember to create a copy of  <strong>config.sample.php</strong> as  <strong>config.php </strong> and edit it with your DB values.");
		die($end_string);
	}

	//include core
	require_once 'include/core.php';
	loadModules("*");

	//check if config works
	$database = getSQLDB();
	if(!$database)
	{
		showMessage("Cannot connect to database, check that the info inside config.php is correct and your databse running.");
		die($end_string);
	}

	//check if there is data still
	$system = getModule("system");
	$is_ready = $system->checkReady();

	if( !$is_ready )
	{
		showMessage("System hasnt been installed. Cannot save or restore backup.","warning");
		die($end_string);
	}

	if(!$console)
	{
		showMessage("Backup WEB version not supported, you must use the command line interface.","warning");
		die($end_string);
	}

	clearDebugLog();

	if( count($argv) < 2 )
	{
		showMessage("Parameters missing. usage: backup create|restore backup_name","warning");
		die($end_string);
	}

	if( $argv[1] == "create" )
	{
		showMessage("Creating Backup","primary");
		if( $system->createBackup(  $argv[2] ) )
			showMessage("Backup created.","success");
		else
			showMessage("Something went wrong.","warning");
	}
	else if( $argv[1] == "list" )
	{
		showMessage(" Listing available backups:","primary");
		$backups = $system->getBackupsList();
		if(!$backups || count($backups) == 0)
			showMessage( " No backups found", "primary" );
		else
			foreach( $backups as $i => $backup_info )
				showMessage( " * " . $backup_info["name"] . "     " . date ("F d Y H:i:s", $backup_info["time"]), "primary" );
	}
	else if( $argv[1] == "dump" )
	{
		if( count($argv) > 2 )
		{
			showMessage("Dumping Backup","primary");
			if( $system->restoreBackup( $argv[2], true ) )
				showMessage("Backup dumped to folder.","success");
			else
				showMessage("Something went wrong.","warning");
		}
	}
	else if( $argv[1] == "restore" )
	{
		if( count($argv) < 3 )
		{
			showMessage(" No backup specified, listing available backups:","primary");
			$backups = $system->getBackupsList();
			if(!$backups || count($backups) == 0)
				showMessage( " No backups found", "primary" );
			else
				foreach( $backups as $i => $backup_info )
					showMessage( " * " . $backup_info["name"] . "     " . date ("F d Y H:i:s", $backup_info["time"]), "primary" );
		}
		else
		{
			showMessage("Restoring Backup","primary");
			if( $system->restoreBackup(  $argv[2] ) )
				showMessage("Backup restored.","success");
			else
				showMessage("Something went wrong.","warning");
		}
	}
	else
		showMessage("Wrong action.","error");

?>