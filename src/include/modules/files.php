<?php

class FilesModule
{
	// List of internal configurable variables
	//********************************************
	private static $USER_UNIT_SALT = "this is my unit"; //salt used for the name of the new units (avoid people guessing unit names)
	private static $MAX_UNITS_PER_USER = 5; 
	private static $DEFAULT_UNIT_SIZE = 1000000;
	private static $MIN_UNIT_SIZE = 50000;
	private static $MAX_UNIT_SIZE = 100000000;
	private static $PREVIEW_IMAGE_SIZE = 128; //in pixels
	private static $MAX_PREVIEW_FILE_SIZE = 50000;//in bytes
	private static $RESTART_CODE = "doomsday"; //internal module restart password


	//this is used to store the result of any call
	public $result = null;
	public $last_error = "";


	public $users_created_limit = 0;

	//called always
	function __construct() {
	}

	public function processAction($action)
	{
		header('Content-Type: application/json');

		$this->result = Array();
		$this->result["debug"] = Array();

		switch($action)
		{
			case "createUnit": $this->actionCreateUnit(); break; //create a new unit
			case "getUnits": $this->actionGetUnits(); break; //get files inside one folder
			case "shareUnit": $this->actionShareUnit(); break; //create a new unit
			case "getUnitInfo": $this->actionGetUnitInfo(); break; //get info about all the users in a unit
			case "deleteUnit": $this->actionDeleteUnit(); break; //create a new unit
			case "getFolders":	$this->actionGetFolders(); break; //get folders tree
			case "createFolder": $this->actionCreateFolder(); break; //create a folder
			case "getFilesInFolder": $this->actionGetFilesInFolder(); break; //get files inside one folder
			case "getFilesTree": $this->actionGetFilesTree(); break; //get all files info
			case "searchByCategory": $this->actionSearchByCategory(); break; //get files matching a category
			case "getFileInfo": $this->actionGetFileInfo(); break; //get metainfo about one file
			case "uploadFile": $this->actionUploadFile(); break; //upload a file
			case "deleteFile": 	$this->actionDeleteFile(); break; //delete a file (by id)
			case "moveFile": $this->actionMoveFile(); break; //change a file (also rename)
			case "updateFile": $this->actionUpdateFile(); break; //update a file with new content

			case "updateFilePreview": $this->actionUpdateFilePreview(); break; //update file preview image
			case "updateFileInfo":$this->actionUpdateFileInfo(); break; //update file meta info
			//case "restart":	$this->actionRestart(); break; //delete all
			default:
				//nothing
				$this->result["status"] = 0;
				$this->result["msg"] = 'action unknown: ' . $action;
		}

		$this->result["debug"] = getDebugLog();

		//the response is encoded in JSON on AJAX calls
		print json_encode($this->result);
	}

	//get units available to user
	public function actionGetUnits()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$this->result["msg"] = "retrieving units";
		$this->result["status"] = 1;
		$this->result["data_type"] = "units";
		$this->result["data"] = $this->getUserUnits( $user->id );
	}

	public function actionCreateUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["unit_name"]) || !isset($_REQUEST["size"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//check how many units does he have
		$units = $this->getUserUnits($user->id);
		if($max_units > 0 && count($units) >= $max_units)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many units';
			return;
		}

		$size = intval($_REQUEST["size"]);
		if($size < self::$MIN_UNIT_SIZE || $size > self::$MAX_UNIT_SIZE )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'invalid size';
			return;
		}

		if($user->used_space + $size > $user->total_space )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought free space';
			return;
		}

		//create unit and change user free space
		$unit = $this->createUnit( $user->id, null, $size, $unit_name, true );

		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'error creating unit';
			return;
		}

		//update user
		$user = getModule("user")->getUser( $user->id );

		$this->result["status"] = 1;
		$this->result["unit"] = $unit;
		$this->result["user"] = $user;
		$this->result["msg"] = 'unit created';
		return;
	}

	public function actionShareUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$mode = "READ";
		$max_units = self::$MAX_UNITS_PER_USER;

		if(!isset($_REQUEST["unit_name"]) || !isset($_REQUEST["username"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get other user
		$usermodule = getModule("user");
		$target_username = $_REQUEST["username"];
		$target_user = null;
		if( filter_var($target_username, FILTER_VALIDATE_EMAIL) )
			$target_user = $usermodule->getUserByMail($target_username);
		else
			$target_user = $usermodule->getUserByName($target_username);

		if(!$target_user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not found';
			return;
		}

		//check how many units does he have
		$units = $this->getUserUnits($target_user->id);
		if($max_units > 0 && count($units) >= $max_units)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many units';
			return;
		}

		//is already in unit
		foreach($units as $i => $u)
		{
			if($u->name == $unit_name)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'user already in unit';
				return;
			}
		}

		//add privileges
		if( !$this->setPrivileges($unit->id, $target_user->id, $mode ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem giving rights to unit';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'user added';
		return true;
	}

	public function actionDeleteUnit()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$mode = "READ";

		if(!isset($_REQUEST["unit_name"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if($unit->author_id != $user->id)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit can only be removed by creator';
			return;
		}

		//TODO
		//$this->deleteUnit( $unit->id );
	}

	public function actionGetUnitInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit_name"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit_name = $_REQUEST["unit_name"];
		if($unit_name == "")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong unit name';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get users
		$users = $this->getUnitUsers( $unit->id );
		$unit->users = $users;

		$this->result["status"] = 1;
		$this->result["msg"] = 'unit info';
		$this->result["unit"] = $unit;
		$this->result["users"] = $users;
	}

	public function actionGetFolders()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["unit"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//get unit only if user has rights
		$unit = $this->getUnitByName( $_REQUEST["unit"], $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFolders check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		$this->result["msg"] = "retrieving tree";
		$this->result["status"] = 1;
		$this->result["data"] = $this->getFolders( $unit->name );
	}

	public function actionGetFilesTree()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["folder"]) || !isset($_REQUEST["unit"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$folder = $_REQUEST["folder"];
		$unit_id = intval($_REQUEST["unit"]);

		$unit = $this->getUnitByName( $unit_id, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFilesTree check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get all files
		$this->result["status"] = 1;
		$this->result["data"] = $this->getFilesFromDB( $unit_id, "", true );
		$this->result["msg"] = 'files tree';
	}

	public function actionCreateFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$unit = null;
		$folder = null;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );			
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionCreateFolder getUnitByName");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		if(!$unit->mode || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot write';
			return;
		}

		if( strpos($folder,"..") != FALSE )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong folder name';
			return;
		}

		$fullpath = $this->clearPathName( $unit->name . "/". $folder );

		self::createFolder( $fullpath );
		$this->result["msg"] = "folder created";
		$this->result["data"] = $fullpath;
		$this->result["status"] = 1;
	}

	public function actionGetFilesInFolder()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(isset($_REQUEST["fullpath"]))
		{
			$path_info = $this->parsePath( $_REQUEST["fullpath"], true );
			$unit_name = $path_info->unit;
			$folder = $path_info->folder;
		}
		else if(isset($_REQUEST["folder"]) && isset($_REQUEST["unit"]))
		{
			$unit_name = $_REQUEST["unit"];
			$folder = $_REQUEST["folder"];
			$folder = $this->clearPathName( $folder );
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$unit = $this->getUnitByName( $unit_name, $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionGetFilesInFolder getUnitByName: " . $unit_name);
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//get files from DB
		$dbfiles = $this->getFilesFromDB($unit->id, $folder);
		if(!$dbfiles)
		{
			$this->result["msg"] = "no files";
			$this->result["status"] = 1;
			return;
		}

		foreach($dbfiles as $i => $file)
		{
			unset( $file->id );
			unset( $file->author_id );
			unset( $file->unit_id );
			unset( $file->folder );
		}

		$this->result["msg"] = "retrieving files";
		$this->result["status"] = 1;
		$this->result["unit"] = $unit_name;
		$this->result["folder"] = $folder;
		$this->result["data"] = $dbfiles;
	}


	public function actionSearchByCategory()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["category"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$category = addslashes($_REQUEST["category"]);

		//get units of user
		$units = $this->getUserUnits($user->id);

		$dbfiles = Array();
		foreach($units as $i => $unit)
		{
			$found = $this->getFilesFromDBByCategory( $unit->id, $category );
			foreach($founf as $j => $file)
			{
				unset($file->unit_id);
				unset($file->author_id);
			}
			$dbfiles[$unit->name] = $found;
		}
		$this->result["msg"] = "retrieving files";
		$this->result["status"] = 1;
		$this->result["data"] = $dbfiles;
	}


	public function actionGetFileInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'no fullpath supplied';
			return;
		}

		$fullpath = $_REQUEST["fullpath"];

		//get file from DB
		$file = $this->getFileInfoByFullpath($fullpath);
		if(!$file)
		{
			$this->result["msg"] = "no file found";
			$this->result["status"] = 0;
			return;
		}

		$this->result["fullpath"] = $fullpath;
		$this->result["msg"] = "retrieving file";
		$this->result["status"] = 1;
		$this->result["data"] = $file;
	}

	public function actionUploadFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		//"data" is not tested here because it could come in $_FILES
		if(!isset($_REQUEST["unit"]) || !isset($_REQUEST["folder"]) || !isset($_REQUEST["filename"]) || !isset($_REQUEST["category"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//check unit
		$unit = $this->getUnitByName( $_REQUEST["unit"], $user->id );
		if(!$unit)
		{
			$this->result["status"] = -1;
			debug("actionUploadFile getUnitByName");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}
		//debug("Unit found: " . $unit->id );

		//check privileges
		if(!$unit->mode || $unit->mode == "" || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUploadFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		$encoding = "text";
		if( isset($_REQUEST["encoding"]) && $_REQUEST["encoding"] != "")
			$encoding = $_REQUEST["encoding"];

		if( $encoding == "file" )
		{
			if(!isset($_FILES["data"]))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'file missing';
				return false;
			}

			$file = $_FILES["data"];
			if(!$file)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'file not found';
				return false;
			}

			$err = "";
			if(isset($file["error"]))
			{
				$errnum = $file["error"];
				switch ($errnum)
				{
					case UPLOAD_ERR_OK:	break;
					case UPLOAD_ERR_INI_SIZE:
					case UPLOAD_ERR_FORM_SIZE:
						$max_upload = (int)(ini_get('upload_max_filesize'));
						$max_post = (int)(ini_get('post_max_size'));
						$memory_limit = (int)(ini_get('memory_limit'));
						$upload_mb = min($max_upload, $max_post, $memory_limit);
						$err = 'File too large (limit of '.$upload_mb.' MBs).';
						break;
					case UPLOAD_ERR_PARTIAL:
						$err = 'File upload was not completed.';
						break;
					case UPLOAD_ERR_NO_FILE:
						$err = 'Zero-length file uploaded.';
						break;
					default:
						break;
				}
			}

			if($err != "")
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'error uploading file';
				$this->result["error"] = $err;
				return;
			}

			//Read data (TODO: optimize, move file directly)
			$data = file_get_contents( $file["tmp_name"] );
			if($data == false)
			{
				debug( "Filename: " . $file["tmp_name"] );
				$this->result["status"] = -1;
				$this->result["msg"] = 'error reading file';
				return;
			}
		}
		else if(!isset($_REQUEST["data"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'data params missing';
			return;
		}
		else
			$data = $_REQUEST["data"];

		//retrive all metadata
		if($encoding == "base64")
			$data = base64_decode($data);

		$preview = null;
		if( isset($_REQUEST["preview"]) )
			$preview = $_REQUEST["preview"];

		$metadata = "";
		if(isset($_REQUEST["metadata"]))
			$metadata = $_REQUEST["metadata"];

		//clean up the path name
		$folder =  $_REQUEST["folder"];
		$path_info = pathinfo($folder);
		$dirname = $path_info["dirname"];
		$folder = $dirname . "/" . $path_info["basename"];
		if( substr($folder, 0, 2) == "./" )
			$folder = substr($folder, 2);

		//check space stuff
		$bytes = strlen( $data );
		$unit_size = $unit->used_size;
		$diff = $bytes; //difference in used space between before storing and after storing the file
		if( $this->fileExist($fullpath) ) //file exist
		{
			$old_file_size = $this->getFileSize( $fullpath );
			$diff = $bytes - $old_file_size;
		}

		if( $unit->used_size + $diff > $unit->total_size )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "Not enough free space";
			return;
		}

		//store file
		$file_id = $this->storeFile( $user->id, $unit->id, $folder, $_REQUEST["filename"], $data, $_REQUEST["category"], $metadata );
		if($file_id == null)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//update unit used size
		if(!$this->changeUnitUsedSize( $unit->id, $diff, true ))
			debug("Something went wrong when changing used size: " . $diff);
		$unit_size += $diff;

		if($preview)
		{
			if( $this->updateFilePreview($file_id, $preview) )
				debug("Saved thumbnail");
		}
		else if( isset($_REQUEST["generate_preview"]) )
		{
			if($this->generateFilePreview( $file_id ))
				debug("Generated thumbnail");
		}

		$unit->used_size = $unit_size;

		$this->result["unit"] = $unit;
		$this->result["status"] = 1;
		$this->result["msg"] = 'file saved';
		$this->result["id"] = $file_id;
	}

	public function actionDeleteFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$file = null;

		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		/* 
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || !$unit->mode || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionDeleteFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}
		*/

		//compute the size to reduce from the quota
		$fullpath = $file->unit_name . "/" . $file->folder . "/" . $file->filename;
		$bytes = $this->getFileSize( $fullpath );
		if($bytes === false)
		{
			$this->result["status"] = -1;
			debug("Filesize of " . $fullpath );
			$this->result["msg"] = "File size cannot be computed";
			return;
		}

		//delete the file
		if( !$this->deleteFileById( $file->id, $user->id ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->changeUnitUsedSize( $file->unit_id, -$bytes, true );
		$unit = $this->getUnit( $file->unit_id );

		$this->result["unit"] = $unit;
		$this->result["status"] = 1;
		$this->result["msg"] = "file deleted";
	}

	public function actionMoveFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		$file = null;

		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		$fullpath = $file->fullpath;

		if(!isset($_REQUEST["new_fullpath"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		//check privileges in origin file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//check privileges in destination unit
		$newfile_info = $this->parsePath( $_REQUEST["new_fullpath"] );
		$target_unit = $this->getUnitByName( $newfile_info->unit, $user->id );
		if(!$target_unit || $target_unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionMoveFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//move file (from DB and HD)
		if( !$this->moveFileById( $file->id, $target_unit->id, $newfile_info->folder, $newfile_info->filename ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//compute size
		$target_fullpath = $target_unit->name . "/" . $newfile_info->folder . "/" . $newfile_info->filename;
		$filesize = $this->getFileSize( $target_fullpath );
		if($filesize === false)
		{
			debug("ERROR, cannot find file size: " . $target_fullpath);
			$this->result["status"] = -1;
			$this->result["msg"] = "error moving file";
			return;
		}

		//debug( $unit->id . " - " .  $target_unit->id );

		//change quotas from one unit to another
		if($unit->id != $target_unit->id)
		{
			$this->changeUnitUsedSize( $unit->id, -$filesize, true );
			$this->changeUnitUsedSize( $target_unit->id, $filesize, true );
			$unit->used_size -= $filesize;
			$target_unit->used_size += $filesize;
		}

		//in case it has
		$this->renamePreview( $fullpath, $target_fullpath );

		$this->result["status"] = 1;
		$this->result["msg"] = "file moved";
		$this->result["unit"] = $unit;
		$this->result["target_unit"] = $target_unit;
		$this->result["last_error"] = $this->last_error;
	}

	public function actionUpdateFile()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		//which file
		$file = null;
		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		$fullpath = $file->fullpath;

		//check privileges in file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUpdateFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}

		//extract file data
		$encoding = "text";
		if( isset($_REQUEST["encoding"]) )
			$encoding = $_REQUEST["encoding"];

		if( $encoding != "file" )
			$data = $_REQUEST["data"];

		if($encoding == "base64")
			$data = base64_decode($data);
		else if($encoding == "file")
		{
			if(!isset($_FILES["data"]))
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'file missing';
				return;
			}
			$file = $_FILES["data"];
			$data = file( $file["tmp_name"] );
		}

		//compute difference in bytes
		$bytes = strlen($data);
		$old_size = $this->getFileSize($fullpath);
		$diff = $bytes - $old_size;
		if( ($unit->usedsize + $diff) > $unit->totalsize)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "not enough free space";
			return;
		}

		//update metadata
		if(isset($_REQUEST["metadata"]) && (!isset($info->metadata) || (isset($info->metadata) && $_REQUEST["metadata"] != $info->metadata)))
		{
			if( !$this->updateFileInfo($file->id, $_REQUEST["metadata"]) )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = $this->last_error;
				return;
			}
		}

		//update file content
		if( !$this->updateFile($file->id, $data) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		//update used size
		$this->changeUnitUsedSize( $unit->id, $diff, true );

		//update preview if necessary
		if( isset($_REQUEST["preview"]) )
		{
			if(!$this->updateFilePreview( $file->id, $_REQUEST["preview"] ) )
			{
				$this->result["msg"] = "Error updating preview: " . $this->last_error;
				return;
			}
		}

		$unit->used_size += $diff;

		$this->result["status"] = 1;
		$this->result["unit"] = $unit;
		$this->result["msg"] = "file updated";
	}

	public function actionUpdateFilePreview()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["preview"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		//which file
		$file = null;
		if(isset($_REQUEST["fullpath"]))
			$file = $this->getFileInfoByFullpath( $_REQUEST["fullpath"] );
		else if( isset($_REQUEST["file_id"]) )
			$file = $this->getFileInfoById( intval($_REQUEST["file_id"]) );
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "params missing";
			return;
		}

		if(!$file)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "file not found";
			return;
		}

		$fullpath = $file->fullpath;

		//check privileges in file
		$unit = $this->getUnit( $file->unit_id, $user->id );
		if(!$unit || $unit->mode == "READ")
		{
			$this->result["status"] = -1;
			debug("actionUpdateFile check unit mode");
			$this->result["msg"] = 'unit not found or not allowed';
			return;
		}


		if( !$this->updateFilePreview( $file->id, $_REQUEST["preview"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = "preview updated";
	}

	public function actionUpdateFileInfo()
	{
		$user = $this->getUserByToken();
		if(!$user) //result already filled in getTokenUser
			return;

		if(!isset($_REQUEST["file_id"]) || !isset($_REQUEST["info"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if( !$this->updateFileInfo($_REQUEST["file_id"], $_REQUEST["info"], $user->id ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = $this->last_error;
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = "preview updated";
	}

	/*
	public function actionRestart()
	{
		$code = null;
		if(isset($_REQUEST["restart_code"]))
			$code = $_REQUEST["restart_code"];

		if($code != self::$RESTART_CODE)
		{
			$this->result["msg"] = "I can't let you do that, Dave ";
			return;
		}

		$this->restart();
	}
	*/

	public function getUserByToken()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "no session token";
			return null;
		}

		//check token
		$login = getModule("user");
		$user = $login->checkToken( $_REQUEST["token"] );

		if(!$user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "token not valid";
			return null;
		}

		return $user;
	}
	

	//**** EVENTS **********************************************
	/*
	public function onCustomUserFields($fields)
	{
	}
	*/

	public function onUserCreated($user)
	{
		debug("Creating unit for user: " . $user->id);
		$unit = $this->createUnit( $user->id, null, -1, $user->username . " unit" , true );
		if(!$unit || !$unit->id )
		{
			debug("Error, unit not created","red");
			return false;
		}
		else
			debug("unit created: " . $unit->id);

		debug("Creating demo tree");

		$this->storeFile($user->id, $unit->id, "", "root.txt", "this is an example file", "TEXT", "" );
		$this->storeFile($user->id, $unit->id, "/test", "test.txt", "this is an example file", "TEXT", "" );
		$this->storeFile($user->id, $unit->id, "/test/subfolder", "subtest.txt", "this is an example file", "TEXT", "" );
		self::createFolder( $unit->name . "/empty_folder" );
	}

	public function onSystemInfo(&$info)
	{
		$max_upload = (int)(ini_get('upload_max_filesize'));
		$max_post = (int)(ini_get('post_max_size'));
		$memory_limit = (int)(ini_get('memory_limit'));
		$upload_mb = min($max_upload, $max_post, $memory_limit);
		$info["max_filesize"] = $upload_mb * 1024*1024;
		$info["max_units"] = self::$MAX_UNITS_PER_USER;
		$info["extensions"] = VALID_EXTENSIONS;
		$info["files_path"] = substr( FILES_PATH, -1 ) == "/" ? FILES_PATH : FILES_PATH . "/"; //ensure the last character is a slash
		$info["pics_path"] = substr( PICS_PATH, -1 ) == "/" ? PICS_PATH : PICS_PATH . "/"; //ensure the last character is a slash
	}

	//remove extra slashes
	public function clearPathName($name)
	{
		$folders = explode("/",$name);
		$folders = array_filter( $folders );
		return implode("/",$folders);
	}

	public function parsePath($fullpath, $is_folder = false)
	{
		$fullpath = $this->clearPathName($fullpath);

		$t = explode( "/", $fullpath );

		$info = new stdClass();
		$info->unit = array_shift($t);
		if(!$is_folder)
			$info->filename = array_pop($t);
		$info->folder = implode( "/", $t );
		return $info;
	}

	public function isExtensionValid($filename)
	{
		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		//if( !isset( self::$VALID_EXTENSIONS[ $extension ] ) )
		if( strpos( VALID_EXTENSIONS, $extension) === false )
			return false;
		return true;
	}

	//**************************************************
	public function createUnit($user_id, $unit_name, $size, $desc_name = "", $change_user_quota = false)
	{
		if($size == -1)
			$size = self::$DEFAULT_UNIT_SIZE;

		if($size == 0)
		{
			debug("ERROR: unit size is 0");
			return false;
		}

		//generate random unit name
		if(!$unit_name)
			$unit_name = md5( self::$USER_UNIT_SALT . $user_id . time() . rand() );

		if($desc_name == "")
			$desc_name = $unit_name;
		$metadata = "{\"name\":\"".addslashes($desc_name)."\"}";

		//insert DB entry
		$query = "INSERT INTO `".DB_PREFIX."units` (`id` , `name` , `author_id` , `metadata` , `used_size`, `total_size` ) VALUES ( NULL ,'".$unit_name ."',".intval($user_id).",'".$metadata."',0,".intval($size).")";

		$database = getSQLDB();
		$result = $database->query( $query );
		if(!$result)
			return null;

		$unit_id = -1;
		if ($database->insert_id != 0)
			$unit_id = $database->insert_id;

		if ($unit_id == -1)
		{
			debug("error inserting in the db a unit");
			$this->last_error = "DB PROBLEM";
			return null;
		}

		//create folder
		$this->createFolder( $unit_name );

		//give privileges to user
		if( !$this->setPrivileges( $unit_id, $user_id, "ADMIN" ) )
		{
			debug("Problem setting privileges");
			return null;
		}

		if($change_user_quota)
			getModule("user")->changeUserUsedSpace( $user_id, $size, true );

		$unit = new stdClass();
		$unit->id = $unit_id;
		$unit->name = $unit_name;
		$unit->used_size = 0;
		$unit->total_size = $size;
		return $unit;
	}

	//is delta means if the $size number is the result size or you want to add it to the current size
	public function changeUnitUsedSize($id, $size, $is_delta = false)
	{
		global $database;
		if(!is_numeric($size))
			return false;

		$id = intval($id);
		$size = intval($size);

		$database = getSQLDB();
		if($is_delta)
			$query = "UPDATE `".DB_PREFIX."units` SET `used_size` = `used_size` + ".intval($size)." WHERE `id` = ".$id.";";
		else
			$query = "UPDATE `".DB_PREFIX."units` SET `used_size` = ".intval($size)." WHERE `id` = ".$id.";";
		//debug($query);
		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;

		return true;
	}

	public function changeUnitTotalSize($id, $size)
	{
		global $database;

		if(!is_numeric($size))
			return false;

		$id = intval($id);
		$size = intval($size);

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."units` SET `total_size` = ".intval($size)." WHERE `id` = '".$id."';";

		$result = $database->query( $query );
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	public function setPrivileges($unit_id, $user_id, $mode = NULL)
	{
		$database = getSQLDB();

		//remove
		if($mode == NULL)
		{
			$query = "DELETE FROM `".DB_PREFIX."privileges` WHERE `unit_id` = ".intval($unit_id)." AND `user_id` = " . intval($user_id);
			$result = $database->query( $query );
			return true;
		}

		//check privileges
		$query = "SELECT * FROM `".DB_PREFIX."privileges` WHERE `unit_id` = ".intval($unit_id)." AND `user_id` = " . intval($user_id);
		$result = $database->query( $query );

		//no privileges found for this user in this unit
		if ($result === false || $result->num_rows == 0)
		{
			//CREATE
			$query = "INSERT INTO `".DB_PREFIX."privileges` (`id` , `unit_id` , `user_id` , `mode` ) VALUES ( NULL ,".intval($unit_id) .",".intval($user_id).",'".$mode."')";
			$result = $database->query( $query );

			$id = -1;
			if ($database->insert_id != 0)
				$id = $database->insert_id;
			if ($id == -1)
			{
				debug("error inserting privileges in the db");
				$this->last_error = "DB PROBLEM";
				return false;
			}
			return true;
		}

		//UPDATE: get privileges info
		$item = $result->fetch_object();

		//no changes
		if($item->mode == $mode)
			return true;

		$query = "UPDATE `".DB_PREFIX."privileges` SET `mode` = '".$mode."' WHERE `id` = ".$item->id." LIMIT 1";
		$result = $database->query( $query );
		if($database->affected_rows == 0)
			return false;

		return true;
	}

	public function getUserUnits( $user_id )
	{
		//check privileges
		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."privileges` as privileges, `".DB_PREFIX."units` AS units  WHERE `user_id` = " . intval($user_id) . " AND units.id = privileges.unit_id";
		$result = $database->query( $query );
		$units = Array();
		while($unit = $result->fetch_object())
			$units[] = $unit;
		return $units;
	}

	public function getUnit( $unit_id, $user_id = -1 )
	{
		$database = getSQLDB();
		if($user_id != -1)
		{
			//check privileges
			$query = "SELECT * FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."privileges` as privileges WHERE units.id = " . intval($unit_id) . " AND units.id = privileges.unit_id AND privileges.user_id = " . intval($user_id);
		}
		else
			$query = "SELECT * FROM `".DB_PREFIX."units` WHERE `id` = " . intval($unit_id);

		$result = $database->query( $query );
		if(!$result)
			return null;
		return $result->fetch_object();
	}

	public function getUnitByName( $name, $user_id = -1 )
	{
		$database = getSQLDB();
		$name = addslashes($name);

		//check privileges
		if($user_id != -1)
		{
			$query = "SELECT units.*, privileges.mode FROM `".DB_PREFIX."units` AS units, `".DB_PREFIX."privileges` as privileges WHERE units.name = '" . $name . "' AND units.id = privileges.unit_id AND privileges.user_id = " . intval($user_id);
		}
		else
			$query = "SELECT * FROM `".DB_PREFIX."units` WHERE `name` = '" . $name . "'";

		$result = $database->query( $query );
		if(!$result)
			return null;
		return $result->fetch_object();
	}

	public function getUnitUsers( $unit_id )
	{
		$database = getSQLDB();

		$query = "SELECT users.username, privileges.timestamp, privileges.mode FROM `".DB_PREFIX."privileges` AS privileges, `".DB_PREFIX."users` AS users WHERE `unit_id` = " . intval($unit_id) ." AND users.id = privileges.user_id";
		//debug($query);
		$result = $database->query( $query );
		if(!$result)
			return null;
		$users = Array();
		while($user = $result->fetch_object())
			$users[] = $user;
		return $users;
	}


	//doesnt check privileges or modifies unit size
	public function storeFile($user_id, $unit_id, $folder, $filename, $fileData, $category, $metadata )
	{
		$user_id = intval($user_id);
		$unit_id = intval($unit_id);
		$filename = addslashes($filename);
		if ( strlen($folder) > 0 && $folder[0] == "/" ) 
			$folder = substr($folder, 1);
		$folder = addslashes($folder);
		$category = addslashes($category);
		$metadata = addslashes($metadata);

		//SAFETY FIRST
		if( strpos($filename,"..") != FALSE || strpos($filename,"/") != FALSE || strpos($folder,"..") != FALSE)
		{
			debug("Filename contains invalid characters");
			$this->last_error = "Invalid filename";
			return null;
		}

		if( !$this->isExtensionValid( $filename ) )
		{
			debug("invalid extension: " . $filename);
			$this->last_error = "Extension not allowed: " . $filename;
			return null;
		}

		//unit
		$unit = $this->getUnit( $unit_id ); // $user_id)
		if(!$unit)
		{
			debug("ERROR: Unit not found: " . $unit_id);
			return null;
		}

		//final filename
		$fullpath = $unit->name ."/" . $folder . "/" . $filename;

		//already exists?
		$exist = $this->fileExist($fullpath);
		if($exist)
		{

			$file_info = $this->getFileInfoByFullpath($fullpath);
			if(!$file_info)
			{
				debug("something weird happened");
				$this->last_error = "ERROR: file found but no SQL entry";
				return null;
			}

			if($file_info->author_id != $user_id)
			{
				debug("user removing file that doesnt belongs to him");
				$this->last_error = "File belongs to other user";
				return null;
			}		
			$id = $file_info->id;	
			debug("file found with the same name, overwriting");
		}
		else //file dont exist
		{
			//insert DB entry
			$query = "INSERT INTO `".DB_PREFIX."files` (`id` , `filename` , `category` , `unit_id`, `folder` , `metadata` , `author_id`) VALUES ( NULL ,'".$filename ."','".$category."',".$unit_id.",'" . $folder. "', '".$metadata."', ".$user_id .")";
			
			$database = getSQLDB();
			$result = $database->query( $query );

			$id = -1;
			if ($database->insert_id != 0)
				$id = $database->insert_id;
			if ($id == -1)
			{
				debug("error inserting in the db");
				$this->last_error = "DB PROBLEM";
				return null;
			}

			if(!$this->folderExist( $unit->name . "/" . $folder)) 
				$this->createFolder( $unit->name . "/" . $folder );
		}

		//save file in hard drive
		if ($this->writeFile($fullpath, $fileData) != true )
		{
			debug("file couldnt be written");
			$this->last_error = "PROBLEM WRITTING FILE";
			$query = "DELETE FROM `".DB_PREFIX."files` WHERE 'id' = " . $id;
			$result = $database->query( $query );
			if(!isset($database->affected_rows) || $database->affected_rows == 0)
			{
				debug("could remove the wrong file from the DB ?!?!");
				return null;
			}
			//TODO: recover unit size
			return null;
		}

		return $id;
	}

	public function updateFile($file_id, $fileData )
	{
		$file_id = intval($file_id);

		$file_info = $this->getFileInfoById($file_id);
		if( !$file_info )
		{
			$this->last_error = "WRONG ID";
			return false;
		}

		$fullpath = $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename;

		//already exists?
		if(!self::fileExist($fullpath))
		{
			$this->last_error = "FILE NOT FOUND IN HD";
			return false;
		}

		//self::deleteFile($fullpath); //do not need to remove

		//save file in hard drive
		if( !self::writeFile( $fullpath, $fileData ) )
		{
			debug("file couldnt be written");
			$this->last_error = "PROBLEM WRITTING FILE";
			return false;
		}

		return true;
	}

	//PREVIEW ***********************************************

	public function updateFilePreview( $file_id, $fileData )
	{
		if(strlen($fileData) > self::$MAX_PREVIEW_FILE_SIZE)
			return false;

		$file_id = intval($file_id);

		$file_info = $this->getFileInfoById($file_id);
		if($file_info == null)
			return false;

		$fileData = substr($fileData, strpos($fileData, ",")+1); //remove encoding info data
		$fileData = base64_decode($fileData);

		$picspath = self::getPicsFolderName();
		$tn_filename = base64_encode( $file_info->fullpath ) . ".jpg";
		$tn_path = $picspath . "/" . $tn_filename ;

		$size = file_put_contents( $tn_path , $fileData );
		if($size == 0)
			return false;

		chmod( $tn_path, 0775);
		return true;
	}

	public function generateFilePreview( $file_id )
	{
		$file_id = intval($file_id);
		$file_info = $this->getFileInfoById($file_id);
		if($file_info == null)
			return false;

		//direct path
		$realpath = self::getFilesFolderName() . "/" . $file_info->fullpath;
		$info = pathinfo( $realpath );

		$thumbWidth =  self::$PREVIEW_IMAGE_SIZE;

		$ext = strtolower($info['extension']);
		$img = null;

		switch($ext)
		{
			case 'jpeg':
			case 'jpg':
				$img = imagecreatefromjpeg( $realpath );
				break;
			case 'png':
				$img = imagecreatefrompng( $realpath );
				break;
			default:
				return false;
		}

		if(!$img)
		{
			debug("Image couldnt be loaded: " . $file_info->fullpath );
			return false;
		}

		$width = imagesx( $img );
		$height = imagesy( $img );

		// calculate thumbnail size
		$new_width = $thumbWidth;
		$new_height = floor( $height * ( $thumbWidth / $width ) );

		// create a new temporary image
		$tmp_img = imagecreatetruecolor( $new_width, $new_height );

		// copy and resize old image into new image 
		imagecopyresized( $tmp_img, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height );

		$picspath = self::getPicsFolderName();
		$tn_filename = base64_encode( $file_info->fullpath ) . ".jpg";
		debug("PICPATH: " . $file_info->fullpath . " -> " . $tn_filename );
		$tn_path = $picspath . "/" . $tn_filename ;

		// save thumbnail into a file
		if( !imagejpeg( $tmp_img, $tn_path ) )
		{
			debug("Error saving thumbnail: " . $tn_path );
			return false;
		}
		return true;
	}

	public function renamePreview($old_fullpath, $new_fullpath )
	{
		if($old_fullpath == $new_fullpath)
			return true;

		$picspath = self::getPicsFolderName() . "/";

		$old_filename = base64_encode( $old_fullpath ) . ".jpg";
		$new_filename = base64_encode( $new_fullpath ) . ".jpg";

		//no thumbnail
		if( !is_file($picspath . $old_filename ) )
			return false;

		$result = rename( $picspath . $old_filename, $picspath . $new_filename );
		if(!$result)
			debug("Problem moving preview: " . $picspath . $new_filename );
		return $result;
	}

	public function deletePreview($fullpath)
	{
		$picspath = self::getPicsFolderName() . "/";

		$filename = base64_encode( $fullpath ) . ".jpg";

		//no thumbnail
		if( !is_file($picspath . $filename ) )
			return false;

		$result = unlink( $picspath . $filename );
		if(!$result)
			debug("Problem deleting preview: " . $picspath . $filename );
		return $result;
	}

	// File metadata ************************************

	public function updateFileInfo($file_id, $info, $user_id = -1 )
	{
		$file_id = intval($file_id);
		$info = addslashes($info);

		$file_info = $this->getFileInfoById($file_id);
		if($file_info == null)
		{
			$this->last_error = "WRONG ID";
			return false;
		}

		$filter = "";
		if($user_id != -1)
			$filter = " AND author = " . intval( $user_id ); 

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."files` SET `metadata` = '".$info."' WHERE `id` = ".$file_id." ".$filter." LIMIT 1";
		$result = $database->query( $query );
		if($database->affected_rows == 0)
			$this->last_error = "NO CHANGES";

		return true;
	}

	public function deleteFileById($file_id, $user_id = -1)
	{
		$database = getSQLDB();

		$file_id = intval($file_id);

		//need file info to know folder and unit
		$file_info = $this->getFileInfoById( $file_id );
		if($file_info == null)
		{
			$this->last_error = "File not found";
			return false;
		}

		$filter = "";
		if($user_id != -1)
			$filter = " AND author_id = " . intval( $user_id );

		//delete from DB
		$query = "DELETE FROM `".DB_PREFIX."files` WHERE `id` = ". $file_id . " " . $filter;
		$result = $database->query( $query );
		if(!isset($database->affected_rows) || $database->affected_rows == 0)
		{
			$this->last_error = "File dont belong to user";
			return false;
		}

		$fullpath = $file_info->fullpath;

		//delete file
		if( !self::deleteFile( $fullpath ) )
		{
			debug("ERROR DELETING FILE FROM HD");
		}

		//remove pic
		$this->deletePreview( $fullpath );
		return true;
	}

	public function moveFileById($file_id, $new_unit_id, $new_folder, $new_filename)
	{
		$database = getSQLDB();

		$file_id = intval($file_id);
		$new_unit = intval($new_unit);
		$new_folder = $this->clearPathName( $new_folder );

		//SAFETY FIRST
		if( strpos($new_filename,"..") != FALSE || strpos($new_filename,"/") != FALSE )
		{
			debug("Filename contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		if( strpos($new_folder,"..") != FALSE )
		{
			debug("Folder contains invalid characters");
			$this->last_error = "INVALID FILENAME";
			return null;
		}

		//CHECK EXTENSION
		if(! $this->isExtensionValid( $new_filename ) )
		{
			debug("Extension is invalid");
			$this->last_error = "INVALID FILENAME: " . $new_filename;
			return null;
		}


		$file = $this->getFileInfoById($file_id);
		if($file == null)
		{
			debug("WRONG FILE ID");
			$this->last_error = "WRONG FILE ID";
			return false;
		}

		$target_unit = $this->getUnit( $new_unit_id );
		if(!$target_unit)
		{
			debug("WRONG TARGET UNIT: " . $new_unit_id);
			$this->last_error = "WRONG TARGET UNIT";
			return false;
		}

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."files` SET `unit_id` = ".$new_unit_id.", `folder` = '".addslashes($new_folder)."' , `filename` = '".addslashes($new_filename)."' WHERE `id` = ".$file->id;
		
		$result = $database->query( $query );
		if($database->affected_rows == 0)
		{
			$this->last_error = "NOTHING DONE";
			return false;
		}

		if(!self::folderExist( $target_unit->name . "/" . $file->folder) )
			self::createFolder( $target_unit->name . "/" . $file->folder );

		$oldfilepath = $file->unit_name . "/" . $file->folder . "/" . $file->filename;
		$newfilepath = $target_unit->name . "/" .  $new_folder . "/" . $new_filename;

		if( !self::moveFile($oldfilepath, $newfilepath) )	
		{
			debug("Error Moving HD from " . $oldfilepath . "   to   " . $newfilepath );
			$this->last_error = "Problem moving file";
			return false;
		}

		/* previews do not move
		$oldfilepath = $file_info->folder . "_pics/_" . $file_info->id . ".png";
		$newfilepath = $new_folder . "/_pics/_" . $file_info->id . ".png";
		if(!self::folderExist($new_folder . "/_pics/"))
			self::createFolder($new_folder . "/_pics/");
		if( !self::moveFile($oldfilepath,$newfilepath) )	
			$this->last_error = "Problem moving preview";
		*/
		return true;
	}


	/*

	private function getUserFilesInFolder($user_id, $folder = "")
	{
		if( strpos($folder,"..") != FALSE)
		{
			debug("unsafe folder name");
			return null;
		}
		$userfolder = $this->getUserPath($user_id);
		debug($userfolder . "/" . $folder);
		return $this->getFilesInFolder($userfolder . "/" . $folder);
	}

	*/

	public function isReady()
	{
		$database = getSQLDB();

		$tables = array("files", "comments", "units", "privileges");

		foreach($tables as $i => $table)
		{
			$query = "SHOW TABLES LIKE '".DB_PREFIX . $table ."'";
			$result = $database->query( $query );
			if(!$result || $result->num_rows != 1)
			{
				debug("Table not found " . $table . " : " . $query);
				return -1;
			}
		}

		return 1;
	}

	public function preRestart()
	{
		debug("Removing files folders");
		//remove folders: do it in prestart because login could create
		if( $this->folderExist("") )
			$this->delTree("");
	}

	public function restart()
	{
		$database = getSQLDB();
		$login = getModule("user");

		debug("Creating files tables");

		//UNITS
		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."units;";
		$result = $database->query( $query );
			
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."units (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`name` VARCHAR(256) NOT NULL,
			`author_id` INT NOT NULL,
			`metadata` TEXT NOT NULL,
			`used_size` INT NOT NULL,
			`total_size` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL)
			ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			$this->result["msg"] = "Units table not created";
			$this->result["status"] = -1;
			return;
		}
		else
			debug("Units table created");

		//PRIVILEGES
		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."privileges;";
		$result = $database->query( $query );
			
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."privileges (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`user_id` INT NOT NULL,
			`unit_id` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL,
			`mode` ENUM('READ','WRITE','ADMIN') NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			$this->result["msg"] = "Privileges table not created";
			$this->result["status"] = -1;
			return;
		}
		else
			debug("Privileges table created");

		//FILES
		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."files;";
		$result = $database->query( $query );
			
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."files (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`unit_id` INT NOT NULL,
			`folder` VARCHAR(256) NOT NULL,
			`filename` VARCHAR(256) NOT NULL,
			`category` VARCHAR(256) NOT NULL,
			`metadata` TEXT NOT NULL,
			`author_id` INT NOT NULL,
			`size` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL,
			`status` ENUM('DRAFT','PRIVATE','PUBLIC','BLOCKED') NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			$this->result["msg"] = "Files table not created";
			$this->result["status"] = -1;
			return;
		}
		else
			debug("Files table created");

		//COMMENTS
		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."comments;";
		$result = $database->query( $query );
			
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."comments (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`file_id` INT UNSIGNED NOT NULL,
			`info` TEXT NOT NULL,
			`author_id` INT NOT NULL,
			`timestamp` TIMESTAMP NOT NULL
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			$this->result["msg"] = "Comments table not created";
			$this->result["status"] = -1;
			return;
		}
		else
			debug("Comments table created");

		//create folders
		$this->createFolder("");

		//pics
		mkdir( self::getPicsFolderName() );  
		chmod( self::getPicsFolderName(), 0775);

		debug("Files folder created");
	}

	// SANDBOXED FILE ACTIONS ***********************************************************

	public static function getFilesFolderName() 
	{
		//jump back from includes/modules/
		return __DIR__ . "/../../" . FILES_PATH;
	}

	public static function getPicsFolderName() 
	{
		//jump back from includes/modules/
		return __DIR__ . "/../../" . PICS_PATH;
	}

	public function getFolders( $unit_name )
	{
		$folder = self::getFilesFolderName() . "/" . $unit_name . "/";
		//debug($folder);
		return $this->getSubfolders( $folder );
	}

	//recursive search
	private function getSubfolders( $dir )
	{
		$folders = Array();
		error_reporting(0);
		$files = array_diff( scandir($dir), array('.','..') );
		foreach ($files as $file) 
		{
			if (is_dir("$dir/$file") && $file[0] != "_" )
			{
				$folders[$file] = $this->getSubfolders("$dir/$file");
			}
		}
		error_reporting(E_ALL);
		if(count($folders) == 0)
			return null;
		return $folders;
	}

	private static function getFilesInFolder($folder)
	{
		$path = self::getFilesFolderName() . "/" . $folder;
		if(!is_dir($path)) return null;

		$result = Array();
		$files = array_diff(scandir($path), array('.','..'));
		foreach ($files as $file) {
			if (!is_dir($path . "/$file"))
				$result[$file] = filesize($path . "/$file");
		}
		return $result;
	}

	private static function fileExist($path)
	{
		return is_file(self::getFilesFolderName() . "/" . $path);
	}

	private static function getFileSize($path)
	{
		return filesize(self::getFilesFolderName() . "/" . $path);
	}

	private static function folderExist($path)
	{
		return is_dir( self::getFilesFolderName() . "/" . $path );
	}

	private static function delTree($dir)
	{
		$path = self::getFilesFolderName() . "/";
		if($path == "/") return;

		$files = array_diff(scandir($path . $dir), array('.','..'));
		foreach ($files as $file) {
		  (is_dir($path . "$dir/$file")) ? self::delTree("$dir/$file") : unlink($path . "$dir/$file");
		}
		return rmdir($path . $dir);
	} 

	private static function createFolder($dirname, $force = false)
	{
		if( is_dir(self::getFilesFolderName() . "/" . $dirname) )
		{
			if($force)
				self::delTree($dirname);
			else
				return;
		}
		mkdir( self::getFilesFolderName() . "/" . $dirname );  
		chmod( self::getFilesFolderName() . "/" . $dirname, 0775);
	}

	private static function writeFile($fullpath, $data)
	{
		$size = file_put_contents( self::getFilesFolderName() . "/" . $fullpath , $data );
		if($size != 0)
			return true;
		return false;
		/*
		$fp = fopen(  self::getFilesFolderName() . "/" . $fullpath , 'wb');
		if ($fp)
		{
			fwrite($fp,$data);
			fclose($fp);
			return true;
		}
		return false;
		*/
	}

	//also works for renaming
	public static function moveFile($old, $new)
	{
		if( !is_file( self::getFilesFolderName() . "/" . $old ) )
		{
			debug("File not found in HD: " . self::getFilesFolderName() . "/" . $old );
			return false;
		}

		$result = rename( self::getFilesFolderName() . "/" .  $old, self::getFilesFolderName() . "/" . $new );
		if(!$result)
			debug("File cannot be moved to : " . self::getFilesFolderName() . "/" . $new );
		return $result;
	}

	public static function deleteFile($filename)
	{
		if ( is_file( self::getFilesFolderName() . "/" .  $filename ) )
			return unlink( self::getFilesFolderName() . "/" . $filename );
		return false;
	}

	//************ DB *****************************
	public function getDBFolders($unit_id)
	{
		$database = getSQLDB();
		$query = "SELECT DISTINCT `virtualfolder` FROM `".DB_PREFIX."files` WHERE `unit_id` = ". intval($unit_id);
		$result = $database->query( $query );
		$folders = Array();
		//debug($query);
		if($result != null)
			while($folder = $result->fetch_object())
				$folders[] = $folder->virtualfolder;
		return $folders;
	}

	public function getFileInfoById( $file_id )
	{
		$database = getSQLDB();
		$file_id = intval($file_id);

		$query = "SELECT file.*, unit.name AS unit_name FROM `".DB_PREFIX."files` AS file,`".DB_PREFIX."units` AS unit WHERE file.`id` = ". $file_id ." AND file.unit_id = unit.id LIMIT 1";
		//debug($query);
		$result = $database->query( $query );
		if ($result === false) 
			return null;

		$file_info = $result->fetch_object();
		$file_info->fullpath = $this->clearPathName( $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename );
		return $file_info;		
	}

	public function getFileInfoByFullpath($fullpath)
	{
		$database = getSQLDB();

		$folder = dirname($fullpath);
		$folder = $this->clearPathName( $folder );

		$folders = explode("/",$folder);

		$unit = $this->getUnitByName( $folders[0] );
		if(!$unit)
			return null;

		//remove first element, the unit name
		array_shift( $folders ); 
		$folder = implode("/",$folders);

		$unit_id = $unit->id;
		$filename = basename($fullpath);

		$query = "SELECT file.*, unit.name AS unit_name FROM `".DB_PREFIX."files` AS file,`".DB_PREFIX."units` AS unit WHERE `unit_id` = ".intval($unit_id)." AND `folder` = '". $folder."' AND `filename` = '". $filename."' AND file.unit_id = unit.id LIMIT 1";

		$result = $database->query( $query );
		if (!$result)
			return null;

		$file_info = $result->fetch_object();
		$file_info->fullpath = $this->clearPathName( $file_info->unit_name . "/" . $file_info->folder . "/" . $file_info->filename );
		return $file_info;		
	}

	public function getFilesFromDB($unit_id, $folder = "", $subfolders = false, $usernames = true)
	{
		$folder = $this->clearPathName( $folder );
		$folder = addslashes($folder);

		$database = getSQLDB();

		if($usernames)
			$query = "SELECT files.*, users.username AS author_username FROM `".DB_PREFIX."files` AS files, `".DB_PREFIX."users` AS users WHERE `folder` = '" . $folder . ( $subfolders ? "%" : "") ."' AND unit_id = ".intval($unit_id) ." AND users.id = files.author_id";
		else
			$query = "SELECT * FROM `".DB_PREFIX."files` WHERE `folder` = '" . $folder . ( $subfolders ? "%" : "") ."' AND unit_id = ".intval($unit_id);
		
		$result = $database->query( $query );
		if ($result === false) 
			return null;

		$files = Array();
		while($file = $result->fetch_object())
			$files[] = $file;

		//debug($query);
		return $files;		
	}

	public function getFilesFromDBByCategory($unit_id, $category)
	{
		$category = addslashes($category);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."files` WHERE `category` = '" . $category . "' AND unit_id = ".intval($unit_id);
		//debug($query);

		$result = $database->query( $query );
		if (!$result) 
			return null;

		$files = Array();
		while($file = $result->fetch_object())
			$files[] = $file;

		return $files;		
	}

	/*
	public function saveBinaryFile($filename, $data, $size = 0)
	{
		global $lwhs_filespath;
		//error_reporting(0);
		//error_reporting(E_ERROR | E_WARNING | E_PARSE);

		$fp = fopen( $lwhs_filespath . $filename, 'wb');
		if ($fp)
		{
			fwrite($fp,$data);
			fclose($fp);

			//check file size
			$this->result["size"] = filesize($lwhs_filespath.$filename);
			if ($size != 0 && $this->result["size"] != $size )
			{
				$this->result["error"] = "problem on saving, sizes do not match";
				return false;
			}
			else
			{
				$this->result["msg"] = "file saved";
				$this->result["filename"] = $filename;
			}
		}
		else
		{
			$this->result["error"] = "cannot write file";
			return false;
		}
		
		error_reporting(E_ERROR | E_WARNING | E_PARSE);
		return true;
	}
	*/

};

//make it public
registerModule("files", "FilesModule" );
?>
