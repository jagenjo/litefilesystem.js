var session = null;
var system_info = {};
var filesview_mode = "thumbnails";

(function(){

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
			{
				//save session
				window.session = session;
				window.session.onsessionexpired = onSessionExpired;
				refreshUserSpace( session.user.used_space, session.user.total_space );
				$(".login-dialog").hide();
				$(".dashboard").show();
				refreshFilesTable();
			}
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
		});
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



	//check existing session
	LiteFileServer.checkExistingSession( function( server_session ) {
		if(server_session)
		{
			window.session = server_session;
			window.session.onsessionexpired = onSessionExpired;
			refreshUserSpace( session.user.used_space, session.user.total_space );
			$(".login-dialog").hide();
			$(".dashboard").show();
			refreshFilesTable();
		}
		else
			$(".login-dialog").show();
	});

	//CREATE FOLDER
	$(".create-folder-button").click(function(e){
		bootbox.prompt("Folder name", function(data){
			if(!data)
				return;

			var unit = current_unit;
			var folder = data;
			var newpath = unit + "/" + current_folder + "/" + folder;

			session.createFolder( newpath, function(f){
				refreshFolders( unit, function(){
					refreshFiles( newpath );
				});
			}, function(err){
				bootbox.alert(err);			
			});
		});
	});

	//CREATE FILE
	$("#newfile-dialog .submit-button").click(function(e){
		e.stopPropagation();
		e.preventDefault();
		if(!session)
			return;

		var values = getFormValues( $("#newfile-dialog .form-newfile") );
			session.uploadFile( current_unit, current_folder, values.filename, values.filecontent, "TEXT", function(){
				bootbox.alert("File uploaded");
				refreshFiles(current_unit + "/" + current_folder);
			}, function(err){
				bootbox.alert("File error: " + err);
			}, function(f){
				console.log("Progress: " + f );
			});
	
		$('#newfile-dialog').modal('hide');
	});

	//CREATE UNIT
	$(".create-unit-button").click(function(e){
		if(!session)
			return;

		bootbox.prompt({ title:"Unit name",
			value: "new unit",
			callback: function(name) {
				if(!name)
					return;

				var size = 2500000; //in bytes
				session.createUnit( name, size, function(unit, resp){
					if(!unit)
						bootbox.alert(resp.msg);
					if(resp.user)
						refreshUserSpace( resp.user.used_space, resp.user.total_space );
					refreshUnits();
				}, function(err){
					bootbox.alert(err || "Cannot create unit");
				});
			}
		}); 
	});	

	$(".refresh-units-button").click(function(e){
		refreshUnits();
	});

	//SHARE UNIT
	$(".share-unit-button").click(function(e){
		if(!session)
			return;

		bootbox.prompt({ title:"User name or email address",
			value: "",
			callback: function(result) {
				if(!result)
					return;

				session.shareUnit( current_unit, result, function(status, resp){
					bootbox.alert("Unit has been shared");
				}, function(err){
					bootbox.alert(err || "Cannot share unit");
				});
			}
		}); 
	});	

	//SETUP UNIT
	$(".setup-unit-button").click(function(e){
		console.log("setup");
		$("#setup-unit-dialog .inputName").val( current_unit );
		var root = $("#setup-unit-dialog .users");

		session.getUnitInfo( current_unit , function(unit){
			root.empty();
			for(var i in unit.users)
			{
				var user = unit.users[i];
				root.append("<div class='user-item'><span class='glyphicon glyphicon-user' aria-hidden='true'></span> <span class='username'>"+user.username+"</span></div>");
			}
		});
	});

	//enable file drop in units
	var files_table = $(".files-table")[0];
	files_table.addEventListener("dragenter", onDrag,false);
	files_table.addEventListener("dragleave", onDrag,false);
	files_table.addEventListener("dragover", function(e){ e.preventDefault(); return false;},false);
	function onDrag(e)
	{
		if(e.type == "dragenter")
			this.style.backgroundColor = "#EEA";
		else
			this.style.backgroundColor = "";
		return true;
	}
	files_table.addEventListener("dragover", function(e){ e.preventDefault(); return false;},false);
	files_table.addEventListener("drop", function(e) {
		this.style.backgroundColor = "";
		if (e.dataTransfer.files.length) //dragging file from HD
		{
			for(var i = 0; i < e.dataTransfer.files.length; i++)
			{
				var file = e.dataTransfer.files[i];
				showUploadFile( current_unit, current_folder, file.name, file );
			}

			e.preventDefault();
			e.stopPropagation();
			return true;
		}
	});

	//CHANGE FILES VIEW
	$(".changeview-files-button").click(function(){
		filesview_mode = this.dataset["view"];
		refreshFiles( current_unit + "/" + current_folder );
	});

	//PHOTO
	$(".photo-button").on("change", function (event) {
		// Get a reference to the taken picture or chosen file
		var files = event.target.files,
			file;
		if (files && files.length > 0) {
			file = files[0];
			var filename = "photo_" + Date.now() + file.name;
			showUploadFile( current_unit, current_folder, filename, file );
		}
	});
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
			refreshUnitSpace( resp.unit.name, resp.unit.used_size, resp.unit.total_size );

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


var current_unit = "";
var current_folder = "";

function refreshUserSpace( used_space, total_space )
{
	var f = used_space / total_space;
	$(".quota").find(".progress-bar").css("width", ((f * 100)|0) + "%");
	$(".quota").find(".progress-bar .size").html( (used_space / (1024*1024)).toFixed(1) + " MBs"); //"/"+(total_space / (1024*1024)).toFixed(1)+
}

function refreshUnitSpace( unit, used_size, total_size )
{
	var f = used_size / total_size;
	var bar = $("#unit-" + unit + " .unit-size span");
	bar.css("width", ((f*100)|0) + "%" );

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
		for(var i in units)
		{
			var unit = units[i];
			var descname = unit.metadata["name"];
			var elem = document.createElement("button");
			elem.id = "unit-" + unit.name;
			elem.dataset["unit"] = unit.name;
			elem.className = "unit-item btn btn-lg unit-"+unit.name;
			if( current_unit == unit.name )
				elem.className += " selected";

			elem.innerHTML = "<span class='unit-size'><span></span></span><span class='glyphicon glyphicon-hdd' aria-hidden='true'></span> " + descname;
			$(root).append(elem);
			pos++;

			refreshUnitSpace( unit.name, unit.used_size, unit.total_size );

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
							refreshUnitSpace( resp.unit.name, resp.unit.used_size, resp.unit.total_size );
						if(resp.target_unit)
						{
							refreshUnitSpace( resp.target_unit.name, resp.target_unit.used_size, resp.target_unit.total_size );
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
								refreshUnitSpace( resp.unit.name, resp.unit.used_size, resp.unit.total_size );
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


})();