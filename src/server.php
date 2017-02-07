<?php

//all responses must be JSON
header('Content-Type: application/json');

//require lib
if (!file_exists(__DIR__ . "/include/config.php"))
	die('{"status":-10, "msg":"config.php not found, check include/config.sample-php, change it and rename it to include/config.php"}');
require_once 'include/core.php';

//check for actions
if( !isset($_REQUEST["action"]) )
{
	loadModules("*");
	die('{"msg":"no action"}');
}

//retrieve module name and action
$action = $_REQUEST["action"];

$pos = strpos($action,"/");
if ($pos == false)
	die('{"msg":"no module in action"}' . "\n");

$module_name = substr($action,0,$pos);
$module_action = substr($action, $pos + 1, strlen($action) - $pos - 1);

//get module
$module = getModule($module_name);
if($module && method_exists($module,"processAction"))
	$module->processAction($module_action);
else
	echo('{"msg":"module not found"}');

echo "\n";
closeSQLDB();
?>