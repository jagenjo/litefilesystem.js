<?php

//error reporting
error_reporting(E_ALL);

//check if executing from CLI or HTTPD
$is_console = php_sapi_name() == "cli";
if($is_console && count($argv) > 1)
{
	$params = explode( "&",  $argv[1] );
	foreach($params as $p => $param)
	{
		$t = explode( "=", $param );
		if( count($t) > 1 )
			$_REQUEST[ $t[0] ] = $t[1];
	}
}

if( !$is_console )
	$global_url = "http://" . $_SERVER["SERVER_NAME"] . "/" . $_SERVER["REQUEST_URI"];
else
	$global_url = "localhost/";


//require global config vars
if (!file_exists(__DIR__ . "/config.php"))
	die("config.php not found, check include/config.sample-php, change it and rename it to include/config.php");

require_once __DIR__ . "/config.php";

//session storage **************************************
$session_enabled = false;
if(!headers_sent())
	$session_enabled = session_start();

// LOG *****************************
function trace($str)
{
	//writes to log file
	$f = fopen(__DIR__."/trace.log","a");
	fwrite($f, date("Y-m-d H:i:s") . ": " . $str."\n");
	fclose($f);
}

$debug_buffer = Array();
function debug($str, $color = null)
{
	global $debug_buffer, $is_console;

	if($color && $is_console)
	{
		$colors = Array(""=>"\033[0m", "black"=>"\033[30m", "red"=>"\033[31m", "green"=>"\033[32m", "yellow"=>"\033[33m", "blue"=>"\033[34m" );
		if( isset($colors[$color]))
			$str = $colors[$color] . $str . $colors[""];
	}

	if($is_console)
		echo(" LOG: " . $str."\n");
	else
		$debug_buffer[] = $str;
}

function clearDebugLog()
{
	global $debug_buffer;
	$debug_buffer = Array();
}

function getDebugLog()
{
	global $debug_buffer;
	return $debug_buffer;
}

//Modular architecture **********************
$loaded_modules = array();

function registerModule($modulename, $class)
{
	global $loaded_modules;
	$loaded_modules[$modulename] = new $class();
}

function getModule($modulename)
{
	global $loaded_modules;

	if( strpos("..",$modulename) != FALSE)
		return null; //Safety, avoid letting get out of the folder. TODO: IMPROVE THIS!! ITS NOT SAFE

	if( isset( $loaded_modules[$modulename] ) ) //reuse between modules
		return $loaded_modules[$modulename];

	if( file_exists(__DIR__ . "/modules/" . $modulename . ".php") == FALSE)
		return NULL;

	//TODO: dangerous, what if one module adds another one?
	require_once "modules/" . $modulename . ".php";

	return $loaded_modules[$modulename];
}

function loadModules($str)
{
	$result = Array();
	if($str == "*")
	{
		$files = scandir(__DIR__ . '/modules/');
		foreach($files as $file)
		{
			if ($file == '.' || $file == '..' || substr($file,-4) != ".php") continue;
			//require_once 'modules/'.$file;
			$module = getModule( substr($file,0,-4) );
			$result[] = $module;
		}
		return $result;
	}

	$tokens = explode(",",$str);
	foreach($tokens as $k=>$v)
		$result[] = getModule($v);
	return $result;
}

function dispatchEventToModules($event_type, &$data )
{
	$modules = loadModules("*");
	foreach($modules as $module)
	{
		if( method_exists($module, $event_type) )
			call_user_func_array( array($module , $event_type), array(&$data));
	}
}

/* Resources Handlers ******************************/

//SQL database
$mysqli = null;

function getSQLDB()
{
	global $mysqli;
	if( $mysqli ) 
		return $mysqli;

	$mysqli = new mysqli("localhost",DB_USER, DB_PASSWORD);
	if (mysqli_connect_errno())
		return null; //die("SQL Error: " . mysqli_connect_error());

	if( $mysqli->select_db(DB_NAME) == FALSE)
		return null; //die(DB_NAME."' not found, be sure to create the DB");

	return $mysqli;
}

function closeSQLDB()
{
	global $mysqli;
	if( $mysqli ) $mysqli->close();
}

//***** REDIS ********************
$redis = null;
require_once 'extra/Predis/Autoloader.php';

function getRedisDB()
{
	global $redis;
	if ($redis) return $redis;

	Predis\Autoloader::register();
	$redis = new Predis\Client();
	return $redis;
}

?>