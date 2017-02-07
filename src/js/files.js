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


$(".delete-folder-button").click(function(e){
	bootbox.confirm("Are you sure you want to delete the folder '"+current_folder+"'?", function(data){
		if(!data)
			return;

		var unit = current_unit;
		var fullpath = unit + "/" + current_folder;

		session.deleteFolder( fullpath, function(v, resp){
			if(resp.unit)
			{
				units[ resp.unit.name ] = resp.unit;
				refreshUnitInfo( resp.unit );
				refreshUnitSetup( resp.unit.name );
			}
			refreshFolders( unit, function(){
				refreshFiles( unit + "/" );
			});
		}, function(err){
			bootbox.alert(err);			
		});
	});
});

$(".download-folder-button").click(function(e){
	bootbox.confirm("Are you sure you want to download the folder '"+current_folder+"'? It may take a while", function(data){
		if(!data)
			return;

		var unit = current_unit;
		var fullpath = unit + "/" + current_folder;

		var dialog = bootbox.dialog({ message: "Generating compressed file... please wait", backdrop: false, closeButton: false });


		session.downloadFolder( fullpath, function(v, resp){
			dialog.remove();
			if(resp.data)
				window.open( resp.data, "_blank" );
			refreshFolders( unit, function(){
				refreshFiles( unit + "/" );
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
		session.uploadFile( LFS.getFullpath( current_unit, current_folder, values.filename ), values.filecontent, "TEXT", function(v){
			if(!v)
			{
				bootbox.alert("Error creating file");
				return;
			}

			bootbox.alert("File uploaded");
			refreshFiles(current_unit + "/" + current_folder);
		}, function(err){
			bootbox.alert("File error: " + err);
		}, function(f){
			console.log("Progress: " + f );
		});

	$('#newfile-dialog').modal('hide');
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
	}
	else if (e.dataTransfer.items.length) //dragging data from tab
	{
		for(var i = 0; i < e.dataTransfer.items.length; i++)
		{
			var item = e.dataTransfer.items[i];
			if(item.type != "text/uri-list")
				continue;
			var url = e.dataTransfer.getData( item.type );
			if(url.substr(0,7) != "http://")
				continue;

			var file_info = LFS.parsePath( url );

			if(file_info.filename)
				showUploadRemoteFile( current_unit, current_folder, file_info.filename, url );
		}
	}

	e.preventDefault();
	e.stopPropagation();
	return true;
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