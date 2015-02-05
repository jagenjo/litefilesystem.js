<?php 

	$force = false;
	$console = php_sapi_name() == "cli";
	$end_string = $console ? "" : "</div></body></html>";

	if( isset( $_REQUEST["force"] ) || ($console && count($argv) > 1 && $argv[1] == "force"))
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

	require_once 'include/core.php';

	if(ADMIN_PASS == "" || ADMIN_MAIL == "")
	{
		showMessage("Error: Config.php must be changed, edit the config.php and add a password and an email for the admin account.");
		die($end_string);
	}


	//check if config
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
	if( $system->checkReady() && !$force )
	{
		showMessage("All modules seem ready, nothing to do.","warning");
		die($end_string);
	}

	//RESTART
	clearDebugLog();
	showMessage("Creating databases and folders","primary");
	$system->restartSystem();

	//READY
	if( $system->checkReady() )
		showMessage("LiteFileServer installed, you can go to the <a href='index.html'>main page</a>.","success");
	else
	{
		showMessage("Something went wrong.","warning");
		if(!$console) 
			echo('<div class="bs-callout bs-callout-<?=$type?>" id="callout-glyphicons-empty-only">' . getLog() . "</div>");
	}

if(!$console)
	echo $end_string;

?>