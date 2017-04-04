<?php

class UsersModule
{

	// List of internal configurable variables
	//********************************************
	private static $PASS_SALT = GLOBAL_PASS_SALT; //for salting the passwords in the MD5
	private static $ADMIN_PASS = ADMIN_PASS; //default admin password
	private static $ADMIN_MAIL = ADMIN_MAIL; //default admin mail
	private static $SESSION_EXPIRATION_TIME = 44640;  //in minutes, default is one month

	private static $RESETPASS_MAIL_SUBJECT = "Reset password in LiteFileServer";
	private static $RESETPASS_MAIL_CONTENT = "<html><body><p>Somebody issued a reset password for your account in LiteFileServer, if it wasn't you ignore this message.</p>";
	private static $PASS_RECOVERY_SALT = "sprawl of concrete";

	//BE CAREFUL CHANGING THIS
	private static $MASTER_PASS = ""; //master password for all users, leave blank to disable
	private static $MASTER_TOKEN = "foo"; //master token for all, leave blank to disable

	//this is used to store the result of any call
	public $result = null;

	public $users_default_total_space = DEFAULT_USER_SPACE; //in MBs

	public $users_created_limit = 0;
	public $minimum_password_size = 5;
	public $minimum_username_size = 5;

	//called always
	function __construct() {
	}

	//called when an ajax action is requested to this module
	public function processAction($action)
	{
		$this->result = Array();
		$this->result["debug"] = Array();

		if ($action == "login")
			$this->actionLogin();
		else if ($action == "logout")
			$this->actionLogout();
		else if ($action == "checkToken")
			$this->actionCheckToken();
		else if ($action == "forgotPassword")
			$this->actionForgotPassword();
		else if ($action == "resetPassword")
			$this->actionResetPassword();
		else if ($action == "setPassword")
			$this->actionChangePassword();
		else if ($action == "create")
			$this->actionCreateUser();
		else if ($action == "delete")
			$this->actionDeleteUser();
		else if ($action == "addRole")
			$this->actionAddRole();
		else if ($action == "getInfo")
			$this->actionGetUserInfo();
		else if ($action == "getUserData")
			$this->actionGetUserData();
		else if ($action == "setUserData")
			$this->actionSetUserData();
		else if ($action == "exist")
			$this->actionIsUser();
		else if ($action == "setSpace")
			$this->actionSetUserSpace();
		else
		{
			//nothing
			$this->result["status"] = 0;
			$this->result["msg"] = 'no action performed';
		}

		$this->result["debug"] = getDebugLog();

		//the response is encoded in JSON on AJAX calls
		print json_encode( $this->result );
	}

	public function onSystemInfo(&$info)
	{
		$info["user_total_space"] = $this->users_default_total_space * 1024*1024;
	}

	//Action methods *****************************************************
	//Check that everything is ok before doing anything, do and change the result

	public function actionLogin()
	{
		$username = "";
		$password = "";

		if( isset($_REQUEST["loginkey"]) )
		{
			 $userpass = base64_decode( $_REQUEST["loginkey"] );
			 $t = explode("|", $userpass);
			 $username = $t[0];
			 $password = $t[1];
		}
		else if( isset($_REQUEST["username"]) && isset($_REQUEST["password"]))
		{
			 $username = $_REQUEST["username"];
			 $password = $_REQUEST["password"];
		}
		else
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought parameters';
			return;
		}

		$username = addslashes( $username );
		$password = addslashes( $password );

		$user = $this->loginUser($username,$password);
		if(!$user)
		{
			$this->result["status"] = -1;
			$this->result["errcode"] = "WRONG_LOGIN";
			$this->result["msg"] = 'user not found or wrong password';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'user logged in';
		$this->result["session_token"] = $user->token;
		$this->result["user"] = $user;
	}

	public function actionLogout()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought parameters';
			return;
		}

		if( !$this->expireSession($_REQUEST["token"]) )
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'token not valid';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'session removed';
	}

	public function actionCheckToken()
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought parameters';
			return;
		}

		$user = $this->checkToken($_REQUEST["token"]);

		if( !$user )
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'token not valid';
			return;
		}

		$this->result["status"] = 1;
		$this->result["user"] = $user;
		$this->result["msg"] = 'session found';
	}

	public function actionForgotPassword()
	{
		global $global_url;

		if(!isset($_REQUEST["email"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought parameters';
			return;
		}

		$email = $_REQUEST["email"];

		//check session to see if it is an attack
		if(isset($_SESSION["forgot_password"]) && $_SESSION["forgot_password"] >= 10)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many forgot password, wait';
			return;
		}

		/*
		if( isset($_SESSION["forgot_password"]) )
			$_SESSION["forgot_password"] += 1;
		else
			$_SESSION["forgot_password"] = 1;
		*/


		$user = $this->getUserByMail( $email );
		if( $user == null )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not found';
			return;
		}

		//check table to see how many resets we have
		$forgots = $this->getPasswordRecovery( $user->id );

		/*
		if( count($forgots) > 5 )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many resets sent';
			return;
		}
		*/

		$redirect = null;
		if(isset($_REQUEST["redirect"]))
			$redirect = $_REQUEST["redirect"];

		//create reset entry token
		$token = $this->createPasswordRecovery( $user->id );

		//create the content of the mail
		$link = $global_url . "?action=user/resetPassword&email=".$email."&token=" . $token;
		if($redirect)
			$link .= "&redirect=".urlencode($redirect);

		$content = self::$RESETPASS_MAIL_CONTENT . "\n\n";
		$content .= "<p>Click to reset your password: <a href='" . $link . "' target='_blank'>".$token."</a></p></body></html>";
		$headers = 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'To: '. $user->username .' <'.$user->email.'>'."\r\n";
		$headers .= 'From: '. MAIL_ADDRESS ."\r\n";
		$headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";

		//send email to user with link
		if( !mail( "", self::$RESETPASS_MAIL_SUBJECT, $content, $headers ) )
		{
			debug("Error sending mail");
		}

		//response with ok
		$this->result["status"] = 1;
		//$this->result["user"] = $user;
		$this->result["msg"] = 'password reset mail sent';
	}

	//called from the mail
	public function actionResetPassword()
	{
		if(!isset($_REQUEST["email"]) || !isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'not enought parameters';
			return;
		}

		$email = $_REQUEST["email"];
		$semitoken = $_REQUEST["token"];

		$user = $this->getUserByMail( $email );
		if( $user == null )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'user not found';
			return;
		}

		$token = md5( $semitoken . self::$PASS_RECOVERY_SALT );

		$forgots = $this->getPasswordRecovery( $user->id, $token );
		if( count($forgots) == 0 )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong token';
			return;
		}

		//token is valid, then erase token
		$this->deletePasswordRecovery( $token );

		//expire all existing sessions of this user
		$this->expireAllSessions( $user->id );

		//change user password to random one
		$newpass = md5( $user->username . time() . GLOBAL_PASS_SALT . rand() );
		if(!$this->setPassword( $user->id, $newpass ))
			debug("not changed");	
		
		//redirect
		if(isset($_REQUEST["redirect"]))
		{
			$redirect = urldecode( $_REQUEST["redirect"] );
			$params = 'action=login&email=' . $user->email . '&pass=' . $newpass;
			$query = parse_url($redirect, PHP_URL_QUERY); // Returns a string if the URL has parameters or NULL if not
			if ($query)
				$redirect .= '&' . $params;
			else
				$redirect .= '?' . $params;
			header('Location: '. $redirect );
			die();
			return;
		}

		//response with ok
		$this->result["status"] = 1;
		$this->result["user"] = $user;
		$this->result["new"] = $newpass;
		$this->result["msg"] = 'reset';
	}

	public function actionCreateUser()
	{
		$user = null;

		if(!ALLOW_WEB_REGISTRATION)
		{
			if(isset($_REQUEST["admin_token"]))
				$user = $this->getUserByToken( $_REQUEST["admin_token"] );
			if(!$user || !$user->roles["admin"])
			{
				$this->result["status"] = -1;
				$this->result["msg"] = 'not allowed to create user';
				return;
			}
		}

		//this is the only non-REST action, but just to be sure nothing weird is done
		//safety: block too much users from a single session
		if($this->users_created_limit > 0 && isset($_SESSION["users_created"]) && $_SESSION["users_created"] >= $this->users_created_limit)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'too many users created from this IP';
			return;
		}

		if( !isset($_REQUEST["username"]) || !isset($_REQUEST["password"]) || !isset($_REQUEST["email"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$username = $_REQUEST["username"];
		$password = $_REQUEST["password"];
		$email = $_REQUEST["email"];

		if( $username == "" || $username == "undefined" || strlen($username) < $this->minimum_username_size || !$this->validateUsername( $username ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong username';
			return;
		}

		if( $this->getUserByName($username) != null )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'username already in use';
			return;
		}

		if($password == "" || strlen($password) < $this->minimum_password_size )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong password';
			return;
		}

		if( !filter_var($email, FILTER_VALIDATE_EMAIL) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong email';
			return;
		}

		if( $this->getUserByMail($email) != null )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'email already in use';
			return;
		}

		$userdata = isset($_REQUEST["userdata"]) ? $_REQUEST["userdata"] : "{}";
	
		$id = $this->createUser($_REQUEST["username"],$_REQUEST["password"],$_REQUEST["email"],"",$userdata);
		if($id == false)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem creating the user';
			return;
		}

		if(!isset($_SESSION["users_created"]))
			$_SESSION["users_created"] = 1;
		else 
			$_SESSION["users_created"] += 1;

		//login
		//$user = $this->loginUser($_REQUEST["username"],$_REQUEST["password"]);

		$this->result["status"] = 1;
		$this->result["msg"] = 'user created';
		$this->result["user_id"] = $id;
	}

	public function actionDeleteUser()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		if(!isset($_REQUEST["username"]) || (!isset($_REQUEST["password"]) && !$user->roles["admin"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$username = $_REQUEST["username"];

		if(isset($_REQUEST["password"]))
			$password = $_REQUEST["password"];

		if( $user->username != $username && !$user->roles["admin"] )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "you can't do that";
			return;
		}

		if($user->username != $username)
		{
			$user = $this->getUserByName( $username );
			if(!$user)
			{
				$this->result["status"] = -1;
				$this->result["msg"] = "user not found";
				return;
			}
		}
		else //check pass
		{
			$salted = $this->saltPassword( $password );
			if( $salted != $user->password )
			{
				$this->result["status"] = -1;
				$this->result["msg"] = "wrong password";
				return;
			}
		}

		if( $this->deleteUser( $user ) == false )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'cannot delete user';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'user deleted';
	}

	public function actionGetUserInfo()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		if( !$user->roles["admin"] )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "you can't do that";
			return;
		}

		if(!isset($_REQUEST["username"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$user = $this->getUserByName( $_REQUEST["username"] );
		if (!$user)
			$user = $this->getUserByMail( $_REQUEST["username"] );

		if (!$user)
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'no user found';
			return;
		}

		$this->result["status"] = 1;
		$this->result["data"] = $user;
		$this->result["msg"] = 'user found';
	}

	public function actionGetUserData()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		$this->result["status"] = 1;
		$this->result["userdata"] = $user->data;
		$this->result["msg"] = 'getting user data';
	}

	public function actionSetUserData()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		if(!isset($_REQUEST["userdata"]) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$this->setUserData( $user->id, $_REQUEST["userdata"] );

		$this->result["status"] = 1;
		$this->result["userdata"] = $_REQUEST["userdata"];
		$this->result["msg"] = 'user data modified';
	}

	public function actionSetUserSpace()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		if( !$user->roles["admin"] )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "you can't do that";
			return;
		}

		if(!isset($_REQUEST["username"]) || !isset($_REQUEST["space"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$user = $this->getUserByName($_REQUEST["username"]);

		if (!$user)
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'no user found';
			return;
		}

		$space = intval($_REQUEST["space"]);

		if( !$this->setUserMaxSpace( $user->id , $space ) )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "problem changing user space";
			return;
		}

		$this->result["status"] = 1;
		$this->result["data"] = intval($space);
		$this->result["msg"] = 'space changed';
	}

	public function actionChangePassword()
	{
		$user = $this->actionValidateToken(true);
		if(!$user)
			return;

		if(!isset($_REQUEST["oldpass"]) || !isset($_REQUEST["newpass"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$oldpass = $_REQUEST["oldpass"];
		$newpass = $_REQUEST["newpass"];

		if( $oldpass == $newpass )
		{
			$this->result["status"] = 1;
			$this->result["msg"] = "nothing to do";
			return;
		}

		$oldsalted = $this->saltPassword($oldpass);
		if($oldsalted != $user->password)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'wrong old password';
			return;
		}

		if(strlen($newpass) < 5 || strlen($newpass) > 100)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'password invalid';
			return;
		}

		if( $this->setPassword( $user->id, $newpass ) == false )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'password not changed';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'password changed';
	}

	public function actionAddRole()
	{
		$user = $this->getUserByToken();
		if(!$user)
			return;

		if(!isset($_REQUEST["username"]) || !isset($_REQUEST["role"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		if( !$user->roles["admin"] )
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "you can't do that";
			return;
		}

		if( $this->addUserRole($_REQUEST["username"], $_REQUEST["role"]) == false)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'problem giving role';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'role added';
	}

	public function actionIsUserByName()
	{
		if(!isset($_REQUEST["username"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$user = $this->getUserByName($_REQUEST["username"]);

		if (!$user)
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'no user';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'is user';
	}


	public function actionIsUserByMail()
	{
		if(!isset($_REQUEST["email"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = 'params missing';
			return;
		}

		$user = $this->getUserByMail($_REQUEST["email"]);

		if (!$user)
		{
			$this->result["status"] = 0;
			$this->result["msg"] = 'no user';
			return;
		}

		$this->result["status"] = 1;
		$this->result["msg"] = 'is user';
	}

	//Generic methods ***************************************
	//This methods could be called from other modules as well
	//Do not use REQUEST because they can be called without a request

	public function actionValidateToken( $keep_info = false )
	{
		if(!isset($_REQUEST["token"]))
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "no session token";
			return null;
		}

		//check token
		$user = $this->checkToken( $_REQUEST["token"], $keep_info );

		if(!$user)
		{
			$this->result["status"] = -1;
			$this->result["msg"] = "token not valid";
			return null;
		}

		return $user;
	}

	private function expandUserRowInfo($user, $keep_info = false)
	{
		if(!$keep_info)
			unset($user->password); //hide password

		if (isset($user->roles) && $user->roles != "")
		{
			$roles = explode(",",$user->roles);
			$user->roles = Array();
			foreach($roles as $k => $i)
				$user->roles[ $i ] = true;
		}
		else
			$user->roles = Array();

		$user->id = intval( $user->id );
		$user->used_space = intval( $user->used_space );
		$user->total_space = intval( $user->total_space );
	}

	//allows username or email address
	//returns token
	public function loginUser( $username, $password )
	{
		$username = addslashes($username);
		$password = addslashes($password);
		
		$userquery = " WHERE `username` = '". $username ."' ";
		if( filter_var($username, FILTER_VALIDATE_EMAIL) )
			$userquery = " WHERE `email` = '". $username ."' ";

		$passquery = "";
		if(self::$MASTER_PASS == "" || $password != self::$MASTER_PASS)
		{
			$salted_password = $this->saltPassword($password);
			$passquery = "AND `password` = '".$salted_password."'";
		}

		$database = getSQLDB();

		$query = "SELECT * FROM `".DB_PREFIX."users` ".$userquery." ".$passquery." LIMIT 1";
		$result = $database->query( $query );

		if ($result === false) 
			return null;

		$user = $result->fetch_object();
		if(!$user)
			return null;

		//add some extra info
		$this->expandUserRowInfo($user);

		//generate user key and store temporary
		$token = md5( $user->username . time() . GLOBAL_PASS_SALT . rand() );
		$query = "INSERT INTO `".DB_PREFIX."sessions` (`id` , `user_id` , `token`) VALUES ( NULL , ". intval($user->id).", '".$token."')";
		$result = $database->query( $query );
		if ($database->insert_id == 0)
		{
			debug("cannot insert session");
			return null;
		}

		$user->token = $token;

		return $user;
	}

	//check if token is valid, returns user associated to this token
	public function checkToken($token, $keep_info = false)
	{
		if(self::$MASTER_TOKEN != "" && $token == self::$MASTER_TOKEN)
			return $this->getUser(1); //return admin

		$database = getSQLDB();

		$token = addslashes($token);

		$query = "SELECT * FROM `".DB_PREFIX."sessions` WHERE `token` = '". $token ."' LIMIT 1";
		$result = $database->query( $query );
		if ( !$result || $result->num_rows == 0) 
			return null;

		$item = $result->fetch_object();
		if(!$item)
		{
			debug("imposible, query didnt result nothing");
			return false;
		}

		//check if token is expired
		if( ( $item->timestamp + self::$SESSION_EXPIRATION_TIME * 60 ) > time() )
		{
			//faster than colling expire session, because we use id instead of token
			$query = "DELETE FROM `".DB_PREFIX."sessions` WHERE `id` = ". $item->id;
			$result = $database->query( $query );
			if( $database->affected_rows == 0 )
				debug("Error removing expired session");
			return null;
		}

		return $this->getUser( $item->user_id, $keep_info );
	}

	public function isAdmin($token)
	{
		$user = $this->checkToken($token);
		if(!$user || !isset($user->roles["admin"]) || !$user->roles["admin"] )
			return false;
		return true;
	}


	public function expireSession( $token )
	{
		$token = addslashes($token);

		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."sessions` WHERE `token` = '". $token ."'";
		$result = $database->query( $query );
		if( $database->affected_rows == 0 )
		{
			debug("Error removing expired session");
			return false;
		}

		return true;
	}

	public function expireAllSessions( $user_id )
	{
		$user_id = intval($user_id);

		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."sessions` WHERE `user_id` = '". $user_id ."'";
		$result = $database->query( $query );
		if( $database->affected_rows == 0 )
		{
			debug("Error removing expired session");
			return false;
		}
		return true;
	}

	public function setPassword( $user_id, $newpassword )
	{
		$saltedpassword = $this->saltPassword( $newpassword );
		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."users` SET `password` = '" . $saltedpassword . "' WHERE `id` = " . intval($user_id) . " LIMIT 1";
		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	public function setUserData( $user_id, $userdata )
	{
		$userdata = addslashes($userdata);

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."users` SET `data` = '" . $userdata. "' WHERE `id` = " . intval($user_id) . " LIMIT 1";
		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;
		return true;
	}


	public function saltPassword($password)
	{
		return md5(self::$PASS_SALT . $password);
	}

	//returns user info from user id
	public function getUser( $id, $keep_info = false )
	{
		$id = intval($id);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."users` WHERE id = '". $id ."' LIMIT 1";
		$result = $database->query( $query );

		if ($result->num_rows == 0)
			return null;

		$user = $result->fetch_object();
		$this->expandUserRowInfo($user, $keep_info);
		return $user;
	}

	public function getUsers( $limit = 50, $start = 0 )
	{
		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."users` LIMIT ". intval($start) ."," . intval($limit);
		$result = $database->query( $query );
		$users = Array();
		while($user = $result->fetch_object())
		{
			$this->expandUserRowInfo($user);
			$users[] = $user;
		}
		return $users;
	}

	public function getUserByToken( $token )
	{
		$token = addslashes($token);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."sessions` AS sessions, `".DB_PREFIX."users` AS users WHERE token =  '".$token."' AND sessions.user_id = users.id LIMIT 1";
		$result = $database->query( $query );

		if ($result->num_rows == 0)
			return null;

		$user = $result->fetch_object();
		$this->expandUserRowInfo($user);
		return $user;
	}

	public function getUserByName($username)
	{
		$username = addslashes($username);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."users` WHERE username = '". $username ."' LIMIT 1";
		$result = $database->query( $query );

		if ($result->num_rows == 0)
			return null;

		$user = $result->fetch_object();
		$this->expandUserRowInfo($user);
		return $user;
	}


	public function getUserByMail($email)
	{
		$email = addslashes($email);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."users` WHERE email = '". $email ."' LIMIT 1";
		$result = $database->query( $query );

		if ($result->num_rows == 0)
			return null;

		$user = $result->fetch_object();
		$this->expandUserRowInfo($user);
		return $user;
	}

	public function validateUsername( $str )  //alphanumeric_underscore from stackoverflow
	{
		return preg_match('/^[A-Za-z][A-Za-z0-9]*(?:_[A-Za-z0-9]+)*$/', $str );
	}

	//total_space in bytes
	public function createUser($username, $password, $email, $roles = "", $data = "", $status = "VALID", $total_space = 0)
	{
		if(!$this->validateUsername($username))
		{
			debug("error creating user: invalid username");
			return false;
		}

		//check for existing user
		$user = $this->getUserByName( $username );
		if($user)
		{
			debug("error creating user: user already exist");
			return false;
		}

		$user = $this->getUserByMail( $email );
		if($user)
		{
			debug("error creating user: email already exist");
			return false;
		}

		$username = addslashes($username);
		$salted_password = $this->saltPassword($password);
		$email = addslashes($email);
		if($total_space == 0)
			$total_space = intval( $this->users_default_total_space * 1024*1024); //in bytes

		//insert DB entry
		$query = "INSERT INTO `".DB_PREFIX."users` (`id` , `username` , `password` , `email`, `roles`, `data`, `status`, `used_space`, `total_space`) VALUES ( NULL ,'".$username ."','".$salted_password."','" . $email. "', '".$roles."', '".$data."', '".$status."', 0, ". $total_space .");";
		debug(" + Inserting user in db: " . $username);
		
		$database = getSQLDB();
		$result = $database->query( $query );

		$id = -1;
		if ($database->insert_id != 0)
			$id = $database->insert_id;
		if ($id == -1)
		{
			debug("error inserting in the db");
			return false;
		}

		$user = $this->getUser($id);
		dispatchEventToModules("onUserCreated",$user);

		return $id;
	}

	public function deleteUser($user, $delete_units = true)
	{
		global $database;

		$database = getSQLDB();

		//delete from users
		$query = "DELETE FROM `".DB_PREFIX."users` WHERE `id` = ". $user->id ." LIMIT 1";
		$result = $database->query( $query );
		if(!isset($database->affected_rows) || $database->affected_rows == 0)
		{
			debug("user not found in DB");
			return false;
		}

		//delete from sessions
		$query = "DELETE FROM `".DB_PREFIX."sessions` WHERE `user_id` = '". $user->id ."';";
		$result = $database->query( $query );

		//delete all the info
		dispatchEventToModules("onUserDeleted", $user );

		return true;
	}

	public function addUserRole($id,$role)
	{
		global $database;

		if( strpos($role,",") != false)
			return false;

		$role = addslashes($role);

		//get roles
		$user = $this->getUser($id);

		if ($user == null)
			return false;

		if(	count($user->roles) > 0)
		{
			if(in_array($role,$user->roles))
				return false;
			array_push($user->roles,$role);
			$roles = implode(",",$user->roles);
		}
		else
			$roles = $role;

		$id = intval($id);

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."users` SET `roles` = '".$roles."' WHERE `id` = '".$id."';";

		$result = $database->query( $query );
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	//Files stuff
	public function changeUserUsedSpace($id, $size, $is_delta = false)
	{
		global $database;

		if(!is_numeric($size))
			return false;

		$id = intval($id);
		$size = intval($size);

		$database = getSQLDB();
		if($is_delta)
			$query = "UPDATE `".DB_PREFIX."users` SET `used_space` = `used_space` + ".intval($size)." WHERE `id` = '".$id."';";
		else
			$query = "UPDATE `".DB_PREFIX."users` SET `used_space` = ".intval($size)." WHERE `id` = '".$id."';";

		$result = $database->query( $query );
		if(!$result)
			return false;
		if($database->affected_rows == 0)
			return false;
		return true;
	}

	public function setUserMaxSpace($id, $size)
	{
		global $database;

		$id = intval($id);
		$size = intval($size);

		if($size == 0) 
		{
			debug("Cannot set size to 0");
			return false;
		}

		$database = getSQLDB();
		$query = "UPDATE `".DB_PREFIX."users` SET `total_space` = '".$size."' WHERE `id` = '".$id."';";

		$result = $database->query( $query );
		if($database->affected_rows == 0)
		{
			debug("Affected rows is 0 after changing user total_space");
			return false;
		}
		return true;
	}

	public function getUserUsedSpace($id)
	{
		global $database;
		$database = getSQLDB();
		$query = "SELECT `used_space` FROM `".DB_PREFIX."users` WHERE `id` = '".intval($id)."';";

		$result = $database->query( $query );
		if($result->num_rows != 1)
			return null;

		$space = $result->fetch_object();
		return intval($space->used_space);
	}

	public function getPasswordRecovery( $user_id, $token = null )
	{
		global $database;
		$database = getSQLDB();
		$query = "DELETE FROM `".DB_PREFIX."pass_recovery` WHERE `timestamp` < DATE_SUB( CURRENT_TIME, INTERVAL 1 DAY)";
		$result = $database->query( $query );
		if(!$result)
			debug("Error removing expired password recovery");
		else
			debug( "PASSRESET expireds: " . $database->affected_rows );

		$user_id = intval($user_id);

		$database = getSQLDB();
		$query = "SELECT * FROM `".DB_PREFIX."pass_recovery` WHERE `user_id` = ". $user_id ." ";
		if($token)
			$query .= " AND `token` = '" . addslashes($token) . "'";
		$result = $database->query( $query );
		$entries = Array();
		while( $entry = $result->fetch_object() )
			$entries[] = $entry;
		return $entries;
	}

	public function createPasswordRecovery( $user_id )
	{
		global $database;
		$database = getSQLDB();
		$user_id = intval($user_id);

		$semitoken = md5( $user_id . time() . GLOBAL_PASS_SALT . rand() );
		$token = md5( $semitoken . self::$PASS_RECOVERY_SALT );
		$query = "INSERT INTO `".DB_PREFIX."pass_recovery` (`id` , `user_id` , `token`) VALUES ( NULL , ". intval($user_id).", '".$token."')";
		$result = $database->query( $query );
		if ($database->insert_id == 0)
		{
			debug("cannot insert pass recovery");
			return null;
		}
		return $semitoken;
	}

	public function deletePasswordRecovery( $token )
	{
		global $database;
		$database = getSQLDB();
		$token = addslashes($token);

		$query = "DELETE FROM `".DB_PREFIX."pass_recovery` WHERE `timestamp` < DATE_SUB( CURRENT_TIME, INTERVAL 1 DAY) OR `token` = '" . $token . "'";
		$result = $database->query( $query );
		if(!$result)
			debug("Error removing expired password recovery");
		return true;
	}


	public function isReady()
	{
		$database = getSQLDB();

		$query = "SHOW TABLES LIKE '".DB_PREFIX."users';";
		$result = $database->query( $query );

		if(!$result || $result->num_rows != 1)
		{
			debug("Table USERS not found: " . $query);
			return -1;
		}

		return 1;
	}

	// Restart ******************************************************
	// Creates all the tables and so, be careful calling this method
	// It is automatically called from deploy.php

	public function restart()
	{

		$database = getSQLDB();

		//DROP old tables
		debug("Drop old tables");
		$query = "DROP TABLE IF EXISTS ".DB_PREFIX."users, ".DB_PREFIX."sessions, ".DB_PREFIX."pass_recovery";
		$result = $database->query( $query );

		//Create
		debug("Creating users tables");
		$this->createTables();
	}

	public function createTables()
	{
		$database = getSQLDB();

		//USERS table
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."users (
		  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		  `username` varchar(255) NOT NULL,
		  `password` varchar(255) NOT NULL,
		  `email` varchar(255) NOT NULL,
		  `roles` varchar(255) NOT NULL,
		  `data` text NOT NULL,
		  `used_space` int(10),
		  `total_space` int(10),
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `email` (`email`),
		  `status` ENUM('PENDING','VALID','BANNED') NOT NULL
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Users table not created");
			$this->result["msg"] = "Users table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Users table created");

		//SESSIONS table
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."sessions (
		  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		  `user_id` int(10) NOT NULL,
		  `token` varchar(255) NOT NULL,
		  `timestamp` TIMESTAMP NOT NULL,
		  PRIMARY KEY (id)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Users sessions table not created");
			$this->result["msg"] = "Users sessions table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Users sessions table created");

		//PASS RECOVERY table
		$query = "CREATE TABLE IF NOT EXISTS ".DB_PREFIX."pass_recovery (
		  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		  `user_id` int(10) NOT NULL,
		  `token` varchar(255) NOT NULL,
		  `timestamp` TIMESTAMP NOT NULL,
		  PRIMARY KEY (id)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1";

		$result = $database->query( $query );		
		if ( $result !== TRUE )
		{
			debug("Password recovery table not created");
			$this->result["msg"] = "Password recovery table not created";
			$this->result["status"] = -1;
			return false;
		}
		else
			debug("Password recovery table created");

		return true;
	}

	public function postRestart()
	{
		debug("Creating default users");

		if( $this->createUser("admin", self::$ADMIN_PASS, self::$ADMIN_MAIL, "admin","{}", "VALID" ) == false)
		{
			$this->result["msg"] = "Admin user not created";
			$this->result["status"] = -1;
			return;
		}

		//create public unit


		if(1) //create guest user
			if( $this->createUser("guest", "guest", "guest@gmail.com", "", "{}", "VALID" ) == false)
			{
				$this->result["msg"] = "Guest user not created";
				$this->result["status"] = -1;
				return;
			}
	}

	//used to upgrade tables and so
	public function upgrade()
	{
		debug("Upgrading user tables");
		if(!$this->createTables())
			return false;
		return true;
	}

};

//make it public
registerModule("user", "UsersModule" );
?>