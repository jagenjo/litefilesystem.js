var session = null;
var system_info = {};
var filesview_mode = "thumbnails";
var units = {};
var current_unit = "";
var current_folder = "";
var current_file_item = null;;


//start up
$(".startup-dialog").show();

LiteFileServer.setup("", function(status, resp) {
	console.log("server checked");
	$(".startup-dialog").hide();
	if(status == 1)
	{
		system_info = resp.info;
		console.log("Server ready");
		systemReady();
	}
	else
	{
		console.warn("Server not ready");
		if(status == -10)
			$(".warning-dialog .content").html("LiteFileServer config file not configured, please, check the <strong>config.sample.php</strong> file in includes and after configure it change the name to <strong>config.php</strong>.");
		else
			$(".warning-dialog .content").html("LiteFileServer database not found, please run the <a href='install.php'>install.php</a>.");
		$(".warning-dialog").show();
	}
});

function systemReady()
{
	//LOGIN
	var login_button = Ladda.create( $(".form-signin .submit-button")[0] );
	$(".form-signin").submit( function(e) {
		$(this).css("opacity",0.5);
		login_button.start();
		var values = getFormValues(this);
		console.log(values);

		e.preventDefault();

		//store in the login form so he can see it if he logs out
		$("#inputEmail").val( values["username"] );
		$("#inputPassword").val( values["password"] );

		//create user
		LiteFileServer.login( values["username"], values["password"], function(session, result){
			login_button.stop();
			$(".form-signin").css("opacity",1);

			if( session.status == LiteFileServer.LOGGED )
				onLoggedIn(session);
			else
				bootbox.alert(result.msg);

		});
	});

	$("#login-dialog #inputPassword").on("keydown",function(e){
		if(e.keyCode == 13)
			$("#login-dialog .submit-button").click();
	});

	//FORGOT PASSWORD
	var forgotpassword_button = Ladda.create( $(".form-forgot .forgotpasswordsend-button")[0] );
	$(".form-forgot").submit( function(e) {
		$(this).css("opacity",0.5);
		forgotpassword_button.start();
		var values = getFormValues(this);
		console.log(values);

		e.preventDefault();

		//ask to send email
		LiteFileServer.forgotPassword( values["username"], function( v, result ){
			forgotpassword_button.stop();
			$(".form-forgot").css("opacity",1);
			bootbox.alert(result.msg);
		} , window.location.origin + window.location.pathname );
	});

	//RESET PASSWORD
	var confirm_resetnewpassword_button = Ladda.create( $("#resetpassword-dialog .confirm-resetnewpassword-button")[0] );
	$(".form-resetpass").submit( function(e) {

		if(!session)
			return;

		$(this).css("opacity",0.5);
		confirm_resetnewpassword_button.start();
		var values = getFormValues(this);
		console.log(values);
		e.preventDefault();

		session.setPassword( values["old_password"], values["new_password"], function( v, result ){
			confirm_resetnewpassword_button.stop();
			$(".form-forgot").css("opacity",1);
			bootbox.alert(result.msg);
		});
	});

	//CHANGE PASS
	var confirm_changepassword_button = Ladda.create( $("#changepassword-dialog .confirm-changepassword-button")[0] );
	$(".form-changepass").submit( function(e) {

		if(!session)
			return;

		$(this).css("opacity",0.5);
		confirm_changepassword_button.start();
		var values = getFormValues(this);
		console.log(values);
		e.preventDefault();

		session.setPassword( values["old_password"], values["new_password"], function( v, result ){
			confirm_changepassword_button.stop();
			$(".form-forgot").css("opacity",1);
			bootbox.alert(result.msg);
		});
	});
	

	//CREATE ACCOUNT
	var create_account_button = Ladda.create( $("#signup-dialog .submit-button")[0] );
	$("#signup-dialog form").submit( function(e) {
		var values = getFormValues(this);
		e.preventDefault();
		if(!values["username"] || !values["password"] || !values["email"])
		{
			bootbox.alert("Fill all the fields");
			return;
		}
		
		$(this).css("opacity",0.5);
		create_account_button.start();

		LiteFileServer.createAccount( values["username"], values["password"], values["email"], function(created, resp){
			create_account_button.stop();
			$("#signup-dialog form").css("opacity",1);
			bootbox.alert(created ? "User created" : "Error creating user: " + resp.msg );
			if(created)
			{
				$('#signup-dialog').modal('hide');
				$("#inputEmail").val( values["username"] );
				$("#inputPassword").val( values["password"] );
				$("#login-dialog .submit-button").click();
			}
		},null, session ? session.token : null );
	});

	//LOGOUT
	$(".logout-button").click( function(e) {

		var logout_button = Ladda.create( this );
		if(!session)
			return;

		logout_button.start();
		session.logout( function(session, result) {
			logout_button.stop();
			session = null;
			$(".login-dialog").show();
			$(".dashboard").hide();
		});
	});

	//DELETE
	$("#profile-dialog .delete-account-button").click( function(e) {

		bootbox.prompt("Are you sure you want to delete your account? If you want to continue you must enter your account password", function(v){
			if(!v)
				return;
			session.deleteAccount( v, function(v, resp) { 
				if(v)
					onSessionExpired(); 
				else
					bootbox.alert(resp.msg);
				});
		});
	});

	//ADMIN
	$(".show-backups").click( updateBackupsList );

	var progressbar_code = $(".progress")[0].outerHTML;

	function updateBackupsList(){
		session.getBackupsList( function(v,resp){
			$("#backups-dialog .backups").empty();
			if(!resp)
				return;
			for(var i in resp.data)
			{
				var backup = resp.data[i];
				var elem = $("#backups-dialog .template").clone();
				elem.removeClass("template");
				$(elem).find(".name").html( backup.name );
				$(elem).find(".time").html( backup.pretty_time );
				$(elem).find(".size").html( LFS.getSizeString( backup.size ) );
				var delete_backup_button = $(elem).find(".delete-backup-button");
				delete_backup_button[0].dataset["backup_name"] = backup.name;
				delete_backup_button.click(function(){
					var name = this.dataset["backup_name"];
					session.deleteBackup( name, function(v, resp){
						if(v)
						{
							bootbox.alert("Backup deleted");
							updateBackupsList();
						}
						else
							bootbox.alert("Cannot delete backup");
					});						
				});


				var download_backup_button = $(elem).find(".download-backup-button");
				download_backup_button[0].dataset["backup_link"] = backup.link;
				download_backup_button.click(function(){
					window.location = this.dataset["backup_link"];
				});

				var restore_backup_button = $(elem).find(".restore-backup-button");
				restore_backup_button[0].dataset["backup_name"] = backup.name;
				restore_backup_button.click(function(){
					var name = this.dataset["backup_name"];

					bootbox.prompt({ title:"WARNING: Restoring and old backup will destroy all the current data. To ensure that this is what you want, type the name of the backup",
						value: "",
						callback: function(result) {
							if(!result || result != name)
								return;
							var dialog = bootbox.dialog({ closeButton: false, message: "Restoring backup, please wait, this could take some time..." + progressbar_code});
							session.restoreBackup( name, function(v, resp){
								dialog.remove();
								if(v)
								{
									bootbox.alert("Backup restored");
									updateBackupsList();
								}
								else
									bootbox.alert("Cannot restore backup");
							});	
						}
					}); 
				});

				$("#backups-dialog .backups").append( elem );

			}
		}, session.user.token );
	}

	//BACKUPS
	$(".create-backup-button").click(function(){
		bootbox.prompt({ title:"Create Backup",
			value: "backup_" + (new Date()).getTime(),
			callback: function(result) {
				if(!result)
					return;
				var dialog = bootbox.dialog({ closeButton: false, message: "Creating backup, please wait, this could take some time..." + progressbar_code});
				dialog
				session.createBackup( result, function(v, resp){
					dialog.remove();
					if(v)
					{
						bootbox.alert("Backup created");
						updateBackupsList();
					}
					else
						bootbox.alert("Cannot create backup");
				});
			}
		}); 
	});

	//USER INFO
	$("#userinfo-dialog .usernameInput").keydown(function(event){
		if(event.keyCode == 13) {
		  $("#userinfo-dialog .search-user-button").click();
		  return false;
		}
	  });

	 var selected_user = null;

	$("#userinfo-dialog .search-user-button").click( function(e) {
		var username = $("#userinfo-dialog .usernameInput").val();
		if(!username)
			return;

		session.getUserInfo( username, function(user, resp) { 
			selected_user = user;
			if(!user)
			{
				$("#userinfo-dialog .user-name").val( "" );
				$("#userinfo-dialog .user-email").val( "" );
				$("#userinfo-dialog .user-roles").val( "" );
				$("#userinfo-dialog .user-totalspace").val( "" );
				return;
			}

			$("#userinfo-dialog .user-name").val( user.username );
			$("#userinfo-dialog .user-email").val( user.email );
			$("#userinfo-dialog .user-totalspace").val( user.total_space );
			$("#userinfo-dialog .user-roles").val( Object.keys( user.roles ) );
		});
	});

	$("#userinfo-dialog .savespace-user-button").click( function(e) {
	
		var username = $("#userinfo-dialog .user-name").val();
		var total = $("#userinfo-dialog .user-totalspace").val();
		if(!username || !total)
		{
			bootbox.alert("Something is missing");
			return;
		}

		session.setUserSpace( username, total, function(v,resp){
			if(v != 1)
			{
				$("#userinfo-dialog p").html(resp.msg);
				$("#userinfo-dialog .alert").alert();
			}

		} );
	});

	$("#userinfo-dialog .setpassword-user-button").click( function(e) {
	
		var username = $("#userinfo-dialog .user-name").val();
		var newpass = $("#userinfo-dialog .user-newpassword").val();
		if(!username || !newpass)
		{
			bootbox.alert("Something is missing");
			return;
		}

		session.adminChangeUserPassword( username, newpass, function(v,resp){
			if(v != 1)
			{
				$("#userinfo-dialog p").html(resp.msg);
				$("#userinfo-dialog .alert").alert();
			}
			else
				bootbox.alert("User password changed");
		});
	});

	$("#userinfo-dialog .change-user-role").click( function(e) {
			if(!selected_user)
				return;

			var username = selected_user.username;
			var mode = this.dataset["mode"];
			if( selected_user.roles[ mode ] )
				session.removeUserRole( username, mode, function(r){
					 $("#userinfo-dialog .search-user-button").click();
				});
			else
				session.addUserRole( username, mode, function(r){
					 $("#userinfo-dialog .search-user-button").click();
				});
	});


	$("#userinfo-dialog .delete-user-button").click( function(e) {

		var username = $("#userinfo-dialog .user-name").val();
		if(!username)
		{
			bootbox.alert("Username missing");
			return;
		}

		bootbox.confirm("Are you sure?", function(v){
			if(!v)
				return;

			session.deleteUserAccount( username, function(v,resp){
				if(v == 1)
					bootbox.alert("User account deleted");
				else
					bootbox.alert("Cannot be removed");
			});
		});



		e.preventDefault();
		e.stopPropagation();
		return true;
	});

	//FILE INFO
	$("#fileinfo-dialog .delete-button").click( onDeleteFile );
	$("#fileinfo-dialog .edit-button").click( onEditFile );
	$("#fileinfo-dialog .rename-button").click( onRenameFile );

	$("#fileinfo-dialog .open-button").click( function(e) {
		var url = this.dataset["url"];
		window.open(url,"_blank");
	});

	//check existing session
	LiteFileServer.checkExistingSession( function( server_session ) {
		if(server_session)
			onLoggedIn(server_session);
		else
			$(".login-dialog").show();
	});

	//check if password reset
	if(QueryString["action"])
	{
		if( QueryString["action"] == "login" || QueryString["action"] == "reset")
			LiteFileServer.login( QueryString["email"], QueryString["pass"], function(session, result){
				if( session.status == LiteFileServer.LOGGED )
					onLoggedIn(session);
				else
					bootbox.alert(result.msg);
			});		
	}
}

function onLoggedIn(session)
{
	//save session
	window.session = session;
	window.session.onsessionexpired = onSessionExpired;

	if( session.user.roles && session.user.roles["admin"] )
		$(".admin-options").show();
	else
		$(".admin-options").hide();

	$(".login-dialog").hide();
	$(".dashboard").show();

	refreshUserInfo( session.user );
	refreshFilesTable();

	if( QueryString["action"] == "reset")
	{
		$('#inputResetOldPassword').val(QueryString["pass"]);
		$('#inputResetNewPassword').val("");
		$('#resetpassword-dialog').modal('show');
	}
}

function onSessionExpired()
{
	session = null;
	bootbox.alert("Session expired");
	$(".login-dialog").show();
	$(".dashboard").hide();
}

function showUploadFile( unit, folder, filename, file )
{
	var progress_element = $(".footer-info .upload-progress.template").clone();
	progress_element.removeClass("template");
	progress_element.find(".filename").html( filename );
	$(".footer-info").append( progress_element );

	session.uploadFile( LFS.getFullpath(unit, folder, filename), file, file.type , function(status, resp){
		refreshFiles( unit + "/" + folder );
		if(resp.unit)
			refreshUnitInfo( resp.unit );

		progress_element.find(".progress-bar").addClass("progress-bar-success");
		progress_element.delay(2000).fadeOut(1000, function() { progress_element.remove(); });
	}, function(err){
		bootbox.alert("File error: " + err);
		progress_element.find(".progress-bar").addClass("progress-bar-danger");
		progress_element.delay(2000).fadeOut(1000, function() { progress_element.remove(); });
	}, function(f){
		console.log("Progress: " + f );
		progress_element.find(".progress-bar").css("width", ((f * 100)|0) + "%");
	});
}

function showUploadRemoteFile( unit, folder, filename, url )
{
	session.uploadRemoteFile( url, LFS.getFullpath(unit, folder, filename), function(status, resp) {
		refreshFiles( unit + "/" + folder );
		if(resp.unit)
			refreshUnitInfo( resp.unit );
	});
}


function sessionExpired()
{
	$(".login-dialog").show();
	$(".dashboard").hide();
}


function refreshUserInfo( user )
{
	session.user = user;
	var f = user.used_space / user.total_space;
	$(".quota").find(".progress-bar").css("width", ((f * 100)|0) + "%");
	$(".quota").find(".progress-bar .size").html( LFS.getSizeString(user.used_space) );
	$(".profile-username").html( user.username );
}

function refreshUnitInfo( unit )
{
	var f = unit.used_size / unit.total_size;
	var bar = $("#unit-" + unit.name + " .unit-size span");
	bar.css("width", ((f*100)|0) + "%" );

	$("#unit-" + unit.name + " .name").html( unit.metadata.name );

	if(f > 0.9)
		bar.addClass("danger");
	else
		bar.removeClass("danger");
}


function refreshFilesTable()
{
	if(!current_unit)
		refreshUnits();
}

function selectUnit()
{

}

function refreshUnits()
{
	session.getUnits(function(units){
		var pos = 1;
		var root = $(".units-list .content");
		root.empty();
		window.units = {};
		for(var i in units)
		{
			var unit = units[i];
			window.units[ unit.name ] = unit;
			var descname = unit.metadata["name"];
			var elem = document.createElement("button");
			elem.id = "unit-" + unit.name;
			elem.dataset["unit"] = unit.name;
			elem.className = "unit-item btn btn-lg unit-"+unit.name;
			if( current_unit == unit.name )
				elem.className += " selected";

			elem.innerHTML = "<span class='unit-size'><span></span></span><span class='glyphicon glyphicon-hdd' aria-hidden='true'></span> <span class='name'>" + descname + "</span>";
			$(root).append(elem);
			pos++;

			refreshUnitInfo( unit );

			//draggable
			elem.addEventListener("dragenter", onDrag,false);
			elem.addEventListener("dragleave", onDrag,false);
			elem.addEventListener("dragover", function(e){ e.preventDefault(); return false;},false);

			function onDrag(e)
			{
				if(e.type == "dragenter")
					this.style.backgroundColor = "#EEA";
				else
					this.style.backgroundColor = "";
				return true;
			}

			elem.addEventListener("drop", function(e) {
				this.style.backgroundColor = "";
				var fullpath = e.dataTransfer.getData("text/path");

				//dragging file from files table
				if(fullpath)
				{
					console.log("Move " + fullpath + " to " + this.dataset["unit"] );
					var file = LFS.parsePath( fullpath );
					var target_unit = this.dataset["unit"];
					session.moveFile( fullpath,  target_unit + "/" + file.filename, function(moved, resp){
						if(resp.unit)
							refreshUnitInfo( resp.unit );
						if(resp.target_unit)
						{
							refreshUnitInfo( resp.target_unit );
						}
						refreshFiles( target_unit );
					}, function(err){
						bootbox.alert(err);
					});
				}
				else if (e.dataTransfer.files.length) //dragging file from HD
				{
					for(var i = 0; i < e.dataTransfer.files.length; i++)
					{
						var file = e.dataTransfer.files[i];
						showUploadFile(  this.dataset["unit"], "/", file.name, file );
					}
				}

				//stop actions!
				e.preventDefault();
				e.stopPropagation();
				return true;

			},false);
		}//for

		$(".unit-item").click( function() {
			var that = this;
			$(".unit-item").removeClass("selected");
			$(this).addClass("selected");
			refreshFolders( this.dataset["unit"], function(units){ 
				refreshFiles( that.dataset["unit"] + "/");
			});						
		});

		if(!current_unit)
			$( $(".unit-item")[0] ).click();
	});
}

function refreshFolders( unit_name, on_complete )
{
	current_unit = unit_name;
	current_folder = "/"; //go to root

	session.getFolders( unit_name, function(folders){
		var root = $(".folders-table .content");
		root.empty();

		insert( folders, "", "", 0 );

		function insert(folders, name, path, level)
		{
			var elem = document.createElement("div");
			elem.dataset["folder"] = name;
			elem.dataset["path"] =  LFS.cleanPath( unit_name + "/" + path );
			elem.style.paddingLeft = (20 * level).toFixed() + "px";
			elem.className = "folder-item folder-item-" + elem.dataset["path"].replace(/\//g,"__");

			elem.innerHTML = "<span class='glyphicon' aria-hidden='true'></span> <span class='name'>" + (name||"/") + "</span>";
			$(root).append(elem);

			//draggable
			elem.addEventListener("dragenter", onDrag,false);
			elem.addEventListener("dragleave", onDrag,false);
			elem.addEventListener("dragover", function(e){ e.preventDefault(); return false;},false);

			function onDrag(e)
			{
				if(e.type == "dragenter")
					this.style.backgroundColor = "#EEA";
				else
					this.style.backgroundColor = "";
				return true;
			}

			elem.addEventListener("drop", function(e) {
				this.style.backgroundColor = "";
				var fullpath = e.dataTransfer.getData("text/path");

				//dragging file
				if(fullpath)
				{
					console.log("Move " + fullpath + " to " + this.dataset["path"] );
					var file = LFS.parsePath( fullpath );
					var target_path = this.dataset["path"];

					session.moveFile( fullpath,  target_path + "/" + file.filename, function(){
						console.log("moved");
						refreshFiles( target_path );
					}, function(err){
						bootbox.alert(err);
					});
				}
				else if (e.dataTransfer.files.length) //dragging file from HD
				{
					for(var i = 0; i < e.dataTransfer.files.length; i++)
					{
						var file = e.dataTransfer.files[i];
						showUploadFile( current_unit,  this.dataset["folder"], file.name, file );
					}
				}

				//stop actions!
				e.preventDefault();
				e.stopPropagation();
				return true;

			},false);

			for(var i in folders)
			{
				var folder = folders[i];
				insert(folder, i, path + "/" + i, level + 1);
			}
		}

		$(root).find(".folder-item").click( function() {
			refreshFiles( this.dataset["path"] );
		});

		if(on_complete)
			on_complete(folders);
	});
}

function refreshFiles( fullpath, on_complete )
{
	var info = LFS.parsePath(fullpath,true);

	//change current folder
	current_folder = LFS.cleanPath( info.folder );

	if(current_unit != info.unit)
	{
		$(".unit-item").removeClass("selected");
		$(".unit-" + info.unit).addClass("selected");
	}

	$(".folder-item").removeClass("selected");
	$(".folder-item .glyphicon").removeClass("glyphicon-folder-open").addClass("glyphicon-folder-close");

	//select folder
	var folder_class = LFS.cleanPath( current_unit + "/" + current_folder).replace(/\//g,"__"); 
	$(".folder-item-" + folder_class ).addClass("selected");
	$(".folder-item-" + folder_class + " .glyphicon").addClass("glyphicon-folder-open").removeClass("glyphicon-folder-close");

	//get files in that folder
	session.getFilesByPath( fullpath, function(files){

		var root = $(".files-table .content");
		root.empty();

		var file = null;

		for(var i in files)
		{
			file = files[i];
			
			var item = null;

			if(filesview_mode == "thumbnails")
				item = $("#file-item-template").clone()[0];
			else
				item = $("#file-row-template").clone()[0];
			item.removeAttribute("id");

			item.dataset["path"] = file.fullpath;
			item.dataset["unit"] = info.unit;
			item.dataset["folder"] = info.folder;
			item.dataset["filename"] = file.filename;
			item.dataset["preview"] = LFS.getPreviewPath( file.fullpath );
			item.dataset["size"] = file.size;
			item.dataset["author"] = file.author_username;
			item.dataset["date"] = file.timestamp;
			item.dataset["url"] = LFS.files_path + file.fullpath;

			if(filesview_mode == "thumbnails")
			{
				var img = new Image();
				img.src = item.dataset["preview"];
				img.onerror = function() { this.parentNode.removeChild(this); };
				img.onload = function(){ this.parentNode.classList.add("loaded") }
				$(item).append(img);
			}

			$(item).find(".filename a").html( file.filename ).attr("href", LFS.files_path + file.fullpath );
			$(root).append(item);

			$(item).find(".checkbox").on("click", function(e){
				$(this.parentNode).toggleClass("selected");
				e.stopPropagation();
			});

			$(item).click(function(){
				$(root).find(".selected").removeClass("selected");
				$(this).addClass("selected");
			});

			$(item).dblclick(function(){
				var dialog = $("#fileinfo-dialog");
				var path = "<span class='path'>" + this.dataset["path"].split("/").join("<span class='slash'>/</span>") + "</span>";
				dialog.find(".inputName").html( path );
				var img = dialog.find("img")[0];
				img.style.display = "";
				img.onerror = function(){ this.style.display = "none"; }
				img.setAttribute("src", this.dataset["preview"] );
				dialog.find(".var[data-var='size'] .varvalue").html( LFS.getSizeString( this.dataset["size"] ));
				dialog.find(".var[data-var='author'] .varvalue").html( this.dataset["author"] );
				dialog.find(".var[data-var='date'] .varvalue").html( this.dataset["date"] );

				dialog.find("button").attr("data-path",this.dataset["path"]);
				dialog.find(".open-button")[0].dataset["url"] = this.dataset["url"];
				dialog.modal("show");
			});

			//draggable
			item.addEventListener("dragstart", function(e) {
				e.dataTransfer.setData("text/path", this.dataset["path"]);
				console.log("::", e.dataTransfer.getData("text/path") );
			    //e.dataTransfer.setDragImage(crt, 0, 0);
			},false);

			$(item).find("button").attr("path", item.path );
		}

		/*
		if(!file)
			$(root).append("<div class='file-row-item'>No files found</div>");
		*/


		//actions
		$(root).find(".deletefile-button").click( onDeleteFile );
		$(root).find(".renamefile-button").click( onRenameFile );
		$(root).find(".editfile-button").click( onEditFile );
		$(root).find(".thumbfile-button").click(function(e){
			var path = LFS.getThumbPath( this.dataset["path"] );
			window.open( path, "_blank" );
		});//click

		if(on_complete)
			on_complete(files);

	},function(err){
		bootbox.alert("Folder not found");
	});//request files
}

function onRenameFile(e){
	var fullpath = this.dataset["path"];
	if(!fullpath)
		fullpath = this.parentNode.parentNode.dataset["path"];
	var info = LFS.parsePath(fullpath);
	var filename = info.filename;
	bootbox.prompt({ title:"New filename",
		value: filename,
		callback: function(result) {
			if(!result)
				return;
			info.filename = result;
			var new_fullpath = info.getFullpath();
			session.moveFile( fullpath, new_fullpath, function(moved, resp){
				console.log("renamed");
				refreshFiles( current_folder );
			}, function(err){
				bootbox.alert(err || "Cannot move file");
			});
		}
	}); 
}


function onDeleteFile(e){
	var fullpath = this.dataset["path"];
	if(!fullpath)
		fullpath = this.parentNode.parentNode.dataset["path"];

	bootbox.confirm("Are you sure?", function(result) {
		if(result)
			session.deleteFile( fullpath, function(status, resp){
				if(status)
				{
					$("#fileinfo-dialog").modal("hide");
					console.log("deleted");
					if(resp.unit)
						refreshUnitInfo( resp.unit );
					refreshFiles( current_unit + "/" + current_folder );
				}
			}, function(err){
				if(!status)
					bootbox.alert("File not deleted: " + err);
			});
	}); 
}

function onEditFile()
{
	var fullpath = this.dataset["path"];
	if(!fullpath)
		fullpath = this.parentNode.parentNode.dataset["path"];

	var info = LFS.parsePath(fullpath);
	var filename = info.filename;

	var textarea = null;
	//retrieve file
	LFS.requestFile( fullpath, function(data) {
		bootbox.dialog({ title: filename,
			message: "<textarea id='file-editor-content' class='form-control' rows='3'>" + data + "</textarea>",
			buttons: {
				success: {
				  label: "Save",
				  className: "btn-success",
				  callback: function() {
					var data = $("#file-editor-content").val();
					session.updateFileContent( fullpath, data, function(resp){
						console.log("updated");
					}, function(err){
						bootbox.alert(err);
					});
				  }
				},
				cancel: {
				  label: "Close",
				  className: ""
				}
			}//buttons
		});//dialog 

		textarea = $("#file-editor-content")[0];
		textarea.addEventListener("dragenter", onDragEvent, false);
	});//request file content

	function onDragEvent(evt)
	{
		if(evt.type == "dragenter")
			textarea.style.opacity = 0.5;
		else
			textarea.style.opacity = 1;

		for(var i in evt.dataTransfer.types)
			if(evt.dataTransfer.types[i] == "Files")
			{
				evt.stopPropagation();
				evt.preventDefault();

				textarea.addEventListener("dragexit", onDragEvent, false);
				textarea.addEventListener("dragover", onDragEvent, false);
				textarea.addEventListener("drop", onDrop, false);
			}
	}

	function onDrop(evt)
	{
		textarea.style.opacity = 1;

		textarea.removeEventListener("dragexit", onDragEvent, false);
		textarea.removeEventListener("dragover", onDragEvent, false);
		textarea.removeEventListener("drop", onDrop, false);
		//load file in memory
		onFileDrop(evt);
	}

	function onFileDrop(evt)
	{
		evt.stopPropagation();
		evt.preventDefault();

		var files = evt.dataTransfer.files;
		var count = files.length;

		//only read first file
		var file = files[0];

		var reader = new FileReader();
		reader.onload = function(e) { textarea.value = e.target.result; };
	    reader.readAsText(file);
	}
}

function getFormValues(form)
{
	var values = {};
	$.each( $(form).serializeArray(), function(i, field) {
		values[field.name] = field.value;
	});
	return values;
}



var QueryString = function () {
  // This function is anonymous, is executed immediately and 
  // the return value is assigned to QueryString!
  var query_string = {};
  var query = window.location.search.substring(1);
  var vars = query.split("&");
  for (var i=0;i<vars.length;i++) {
    var pair = vars[i].split("=");
        // If first entry with this name
    if (typeof query_string[pair[0]] === "undefined") {
      query_string[pair[0]] = decodeURIComponent(pair[1]);
        // If second entry with this name
    } else if (typeof query_string[pair[0]] === "string") {
      var arr = [ query_string[pair[0]],decodeURIComponent(pair[1]) ];
      query_string[pair[0]] = arr;
        // If third or later entry with this name
    } else {
      query_string[pair[0]].push(decodeURIComponent(pair[1]));
    }
  } 
    return query_string;
}();



$(document).ready(function() {
  $(window).keydown(function(event){
    if(event.keyCode == 13) {
      event.preventDefault();
      return false;
    }
  });
});