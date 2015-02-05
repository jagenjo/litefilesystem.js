<?php

class SystemModule
{
	//this is used to store the result of any call
	private static $RESTART_CODE = "";
	public $result = null;
	public $version = "0.1a";

	//called always
	function __construct() {
	}

	//called when an ajax action is requested to this module
	public function processAction($action)
	{
		$this->result = Array();
		$this->result["debug"] = Array();

		if ($action == "ready")
			$this->actionCheckReady();
		else
		{
			//nothing
			$this->result["status"] = 0;
			$this->result["msg"] = 'no action performed';
		}

		$this->result["debug"] = getDebugLog();

		//the response is encoded in JSON on AJAX calls
		print json_encode($this->result);
	}

	public function actionRestart()
	{
		$code = null;
		if(isset($_REQUEST["restart_code"]))
			$code = $_REQUEST["restart_code"];

		if(self::$RESTART_CODE == "" || $code != self::$RESTART_CODE)
		{
			$this->result["msg"] = "I can't let you do that, Dave";
			return;
		}

		$this->restartSystem();
	}


	public function actionCheckReady()
	{
		//check databse
		$database = getSQLDB();
		if(!$database)
		{
			$this->result["status"] = -2;
			$this->result["msg"] = "Cannot connect to DB";
			return;
		}

		if( $this->checkReady() != true)
		{
			$this->result["status"] = -1;
			return;
		}

		//gather system info
		$info = Array();
		$info["version"] = $this->version;
		dispatchEventToModules("onSystemInfo",$info);
		$this->result["info"] = $info;
		$this->result["status"] = 1;
	}

	public function checkReady()
	{
		//for every module
		$modules = loadModules("*");
		foreach($modules as $module)
		{
			if( method_exists($module,"isReady") )
			{
				if( $module->isReady() != 1 )
				{
					debug('System not ready, fail at '.get_class($module) );
					return false;
				}
			}
		}

		return true;
	}

	public function restartSystem()
	{
		$tmp = Array();
		debug("Restarting system" );
		dispatchEventToModules("preRestart",$tmp); //remove all
		dispatchEventToModules("restart",$tmp); //create tables and folders
		dispatchEventToModules("postRestart",$tmp); //fill stuff
		debug("System restarted" );
		return true;
	}

};

//make it public
registerModule("system", "SystemModule" );
?>