var session = null;
var system_info = {};
var filesview_mode = "thumbnails";
var units = {};
var current_unit = "";
var current_folder = "";


//start up
$(".startup-dialog").show();
LiteFileServer.checkServer( function(resp) {
	console.log("server checked");
	$(".startup-dialog").hide();
	if(resp.status == 1)
	{
		system_info = resp.info;
		console.log("Server ready");
		systemReady();
	}
	else
	{
		console.warn("Server not ready");
		if(resp.status == -10)
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

		LiteFileServer.login( values["username"], values["password"], function(session, result){
			login_button.stop();
			$(".form-signin").css("opacity",1);

			if( session.status == LiteFileServer.LOGGED )
				onLoggedIn(session);
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
			else
				Bootbox.alert( resp.msg );
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
	$(".delete-account-button").click( function(e) {

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




	//check existing session
	LiteFileServer.checkExistingSession( function( server_session ) {
		if(server_session)
			onLoggedIn(server_session);
		else
			$(".login-dialog").show();
	});


}

function onLoggedIn(session)
{
	//save session
	window.session = session;
	window.session.onsessionexpired = onSessionExpired;

	if( session.user.roles && session.user.roles["admin"] )
		$(".admin-button").show();
	else
		$(".admin-button").hide();

	$(".login-dialog").hide();
	$(".dashboard").show();

	refreshUserInfo( session.user );
	refreshFilesTable();
}

function onSessionExpired()
{
	session = null;
	bootbox.alert("Session expired");
	$(".login-dialog").show();
	$(".dashboard").hide();
}

function showUploadFile( unit, folder, filename, data )
{
	var progress_element = $(".footer-info .upload-progress.template").clone();
	progress_element.removeClass("template");
	progress_element.find(".filename").html( filename );
	$(".footer-info").append( progress_element );

	session.uploadFile( unit, folder, filename, data, "UNKNOWN", function(status, resp){
		//bootbox.alert("File uploaded");
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
			elem.dataset["path"] = unit_name + "/" + path;
			elem.style.paddingLeft = (20 * level).toFixed() + "px";
			elem.className = "folder-item folder-item-" + path.replace(/\//g,"__");

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
	current_folder = info.folder;

	if(current_unit != info.unit)
	{
		$(".unit-item").removeClass("selected");
		$(".unit-" + info.unit).addClass("selected");
	}

	$(".folder-item").removeClass("selected");
	$(".folder-item .glyphicon").removeClass("glyphicon-folder-open").addClass("glyphicon-folder-close");

	var folder_class = current_folder.replace(/\//g,"__"); 
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

			if(filesview_mode == "thumbnails")
			{
				var img = new Image();
				img.src = LFS.getThumbPath( file.fullpath );
				img.onerror = function() { this.parentNode.removeChild(this); };
				$(item).append(img);
			}

			$(item).find(".filename a").html( file.filename ).attr("href", LFS.files_path + file.fullpath );
			$(root).append(item);

			$(item).find(".checkbox").on("click", function(e){
				$(this.parentNode).toggleClass("selected");
			});

			//draggable
			item.addEventListener("dragstart", function(e) {
				e.dataTransfer.setData("text/path", this.dataset["path"]);
				console.log("::", e.dataTransfer.getData("text/path") );
			    //e.dataTransfer.setDragImage(crt, 0, 0);
			},false);
		}

		/*
		if(!file)
			$(root).append("<div class='file-row-item'>No files found</div>");
		*/

		//delete
		$(root).find(".deletefile-button").click(function(e){

			var fullpath = this.parentNode.parentNode.dataset["path"];

			bootbox.confirm("Are you sure?", function(result) {
				if(result)
					session.deleteFile( fullpath, function(status, resp){
						if(status)
						{
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
		});

		//rename
		$(root).find(".renamefile-button").click(function(e){

			var tr = this.parentNode.parentNode;
			var fullpath = tr.dataset["path"];
			bootbox.prompt({ title:"New filename",
				value: tr.dataset["filename"],
				callback: function(result) {
					if(!result)
						return;

					var file = LFS.parsePath( fullpath );
					file.filename = result;
					var new_fullpath = file.getFullpath();
					session.moveFile( fullpath, new_fullpath, function(moved, resp){
						console.log("renamed");
						refreshFiles( current_folder );
					}, function(err){
						bootbox.alert(err || "Cannot move file");
					});
				}
			}); 
		});

		//edit
		$(root).find(".editfile-button").click(function(e){

			var tr = this.parentNode.parentNode;
			var fullpath = tr.dataset["path"];
			var filename = tr.dataset["filename"];
			editFile( fullpath, filename );
		});//click

		//watch thumb
		$(root).find(".thumbfile-button").click(function(e){

			var tr = this.parentNode.parentNode;
			var path = LFS.getThumbPath( tr.dataset["path"] );
			window.open( path, "_blank" );
		});//click

		if(on_complete)
			on_complete(files);

	},function(err){
		bootbox.alert("Folder not found");
	});//request files
}

function editFile(fullpath, filename)
{
	var textarea = null;
	//retrieve file
	LFS.requestFile( fullpath, function(data) {
		bootbox.dialog({ title: filename,
			message: "<textarea id='file-editor-content' class='form-control' rows='3'>" + data + "</textarea>",
			buttons: {
				cancel: {
				  label: "Cancel",
				  className: ""
				},
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


