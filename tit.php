<?php
/*
 *      Tiny Issue Tracker (TIT) v0.1
 * 		SQLite based, single file Issue Tracker
 * 
 *      Copyright 2010 Jwalanta Shrestha <jwalanta at gmail dot com>
 * 		GNU GPL
 */

$TITLE = "My Project";

/*
 * 		Array of users. Format: array("username","md5_password","email")
 *		Note: "admin" user has special powers
 */
$USERS = array(	array("admin",md5("admin"),"admin@mydomain.com"),
				array("user",md5("user"),"user@mydomain.com")
			  );

/*
 * 		Location of SQLITE db file
 *		(If the file doesn't exist, a new one will be created.
 *		 Make sure the folder is writable)
 */
$SQLITE = "/tmp/tit.db";


///////////////////////////////////////////////////////////////////////
////// DO NOT EDIT BEYOND THIS IF YOU DONT KNOW WHAT YOU'RE DOING /////
///////////////////////////////////////////////////////////////////////

// Here we go..
session_start();

// check for login post
if (isset($_POST["login"])){
	$n = check_credentials($_POST["u"],md5($_POST["p"]));
	if ($n>=0){
		$_SESSION['u']=$USERS[$n][0];	// username
		$_SESSION['p']=$USERS[$n][1];	// password
		$_SESSION['e']=$USERS[$n][2];	// email
		
		header("Location: $PHP_SELF");
	}
	else header("Location: $PHP_SELF?loginerror");
}

// check for logout 
if (isset($_GET['logout'])){
	$_SESSION['u']='';	// username
	$_SESSION['p']='';	// password
	$_SESSION['e']='';	// email
	
	header("Location: $PHP_SELF");	
}

// check credentials function
function check_credentials($u, $p){
	global $USERS;
	
	$n=0;
	foreach ($USERS as $user){
		if ($user[0]==$u && $user[1]==$p) return $n;
		$n++;
	}
	return -1;
}

if (isset($_GET['loginerror'])) $message = "Invalid username or password";
$login_html = "<html><head><title>Tiny Issue Tracker</title><style>body{margin:20px;font-family:sans-serif;font-size:11px;} label{display:block;}</style></head>
			   <body><h2>$TITLE - Issue Tracker</h2>$message<form method='POST'>
			   <label>Username</label><input type='text' name='u' />
			   <label>Password</label><input type='password' name='p' />
			   <label></label><input type='submit' name='login' value='Login' />
			   </form></body></html>";

if (check_credentials($_SESSION['u'], $_SESSION['p'])==-1) die($login_html);

// Check if db exists
if (!($db = sqlite_open($SQLITE, 0666, $sqliteerror))) die($sqliteerror);

// create tables if not exist
@sqlite_query($db, 'CREATE TABLE issues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, user TEXT, status INTEGER, entrytime DATETIME)');
@sqlite_query($db, 'CREATE TABLE comments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, description TEXT, entrytime DATETIME)');

if (isset($_GET["id"])){
	// show issue #id
	
	$id=sqlite_escape_string($_GET['id']);
	$issue = sqlite_array_query($db, "SELECT id, title, description, user, status, entrytime FROM issues WHERE id='$id'");
	$comments = sqlite_array_query($db, "SELECT user, description, entrytime FROM comments WHERE issue_id='$id' ORDER BY entrytime DESC");

}

if (count($issue)==0){
	
	unset($issue, $comments);
	// show all issues

	if (isset($_GET["resolved"])) 
		$issues = sqlite_array_query($db, "SELECT id, title, description, user, status, entrytime FROM issues WHERE status=1 ORDER BY entrytime DESC");
	else 
		$issues = sqlite_array_query($db, "SELECT id, title, description, user, status, entrytime FROM issues WHERE (status=0 OR status IS NULL) ORDER BY entrytime DESC");
	
	$mode="list";
}
else {
	$issue = $issue[0];
	$mode="issue";
}

//
// PROCESS ACTIONS
//

// Create / Edit issue
if (isset($_POST["createissue"])){

	$id=sqlite_escape_string($_POST['id']);
	$title=sqlite_escape_string($_POST['title']);
	$description=sqlite_escape_string($_POST['description']);
	$user=$_SESSION['u'];
	$now=date("Y-m-d H:i:s");
	
	if ($id=='')
		$query = "INSERT INTO issues (title, description, user, entrytime) values('$title','$description','$user','$now')"; // create
	else
		$query = "UPDATE issues SET title='$title', description='$description' WHERE id='$id'"; // edit

	if (trim($title)!='') @sqlite_query($db, $query);
	
	header("Location: $PHP_SELF");
	
}

// Delete issue
if (isset($_GET["deleteissue"])){
	$id=sqlite_escape_string($_GET['id']);
	sqlite_query($db, "DELETE FROM issues WHERE id='$id'");
	
	header("Location: $PHP_SELF");
	
}

// Create Comment
if (isset($_POST["createcomment"])){

	$issue_id=sqlite_escape_string($_POST['issue_id']);
	$description=sqlite_escape_string($_POST['description']);
	$user=$_SESSION['u'];
	$now=date("Y-m-d H:i:s");	
	
	if (trim($description)!=''){
		$query = "INSERT INTO comments (issue_id, description, user, entrytime) values('$issue_id','$description','$user','$now')"; // create
		sqlite_query($db, $query);
	}
	
	header("Location: $PHP_SELF?id=$issue_id");
	
}

// Delete Comment
if (isset($_GET["deletecomment"])){
	$id=sqlite_escape_string($_GET['id']);
	$cid=sqlite_escape_string($_GET['cid']);
	sqlite_query($db, "DELETE FROM comments WHERE id='$cid'");
	
	header("Location: $PHP_SELF?id=$id");
}



?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

<head>
	<title><?php echo $TITLE, " - Issue Tracker"; ?></title>
	<meta http-equiv="content-type" content="text/html;charset=utf-8" />
	<style>
		body { font-family: sans-serif; font-size: 11px; background-color: #aaa;}
		a, a:visited{color:#004989; text-decoration:none;}
		a:hover{color: #666; text-decoration: underline;}
		label{ display: block; font-weight: bold;}
		table{border-collapse: collapse;}
		
		#menu{float: right;}
		#container{width: 760px; margin: 0 auto; padding: 20px; background-color: #fff;}
		#create{padding: 15px; background-color: #f2f2f2;}
		.issue{padding:10px 20px; margin: 10px 0; background-color: #f2f2f2;}
		.comment{padding:5px 10px 10px 10px; margin: 10px 0; border: 1px solid #ccc;}
		.hide{display:none;}
		
	</style>
</head>
<body>
<div id='container'>
	<div id="menu">
		<a href="<?php echo $PHP_SELF; ?>" alt="Active Issues">Active Issues</a> | 
		<a href="<?php echo $PHP_SELF; ?>?resolved" alt="Resolved Issues">Resolved Issues</a> | 
		<a href="<?php echo $PHP_SELF; ?>?logout" alt="Logout">Logout</a>
	</div>

	<h1><?php echo $TITLE; ?></h1>

	<h2><a href="#" onclick="document.getElementById('create').className='';document.getElementById('title').focus();"><?php echo ($issue['id']==''?"Create":"Edit"); ?> Issue</a></h2>
	<div id="create" class='<?php echo isset($_GET['editissue'])?'':'hide'; ?>'>
		<a href="#" onclick="document.getElementById('create').className='hide';" style="float: right;">Close</a>
		<form method="POST">
			<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
			<label>Title</label>
			<input type="text" size="50" name="title" id="title" value="<?php echo stripslashes($issue['title']); ?>" /> 
			<label>Description</label>
			<textarea name="description" rows="5" cols="50"><?php echo stripslashes($issue['description']); ?></textarea>
			<label></label>
			<input type="submit" name="createissue" value="<?php echo ($issue['id']==''?"Create":"Edit"); ?>" />
		</form>
	</div>
	
	<?php if ($mode=="list"): ?>
	<div id="list">
	<h2>Issues</h2>
		<table border=1 cellpadding=5>
			<tr>
				<th width="50%">Title</th>
				<th width="20%">Created by</th>
				<th width="20%">Date</th>
				<th>Actions</th>
			</tr>
		
			<?php
			
			foreach ($issues as $issue){
				echo "<tr>\n"; 
				
				echo "<td><a href='?id={$issue['id']}'>{$issue['title']}</a></td>\n";
				echo "<td>{$issue['user']}</td>\n";
				echo "<td>{$issue['entrytime']}</td>\n";
				echo "<td><a href='?editissue&id={$issue['id']}'>Edit</a> <a href='?deleteissue&id={$issue['id']}' onclick='return confirm(\"Are you sure?\");'>Delete</a></td>\n";
				
				echo "</tr>\n";
			}
			
			?>

		</table>
	</div>
	<?php endif; ?>
	
	<?php if ($mode=="issue"): ?>	
	<div id="show">
		<div class="issue">
			<h2><?php echo htmlentities(stripslashes($issue['title'])); ?></h2>
			<p><?php echo str_replace("\n","<br />",htmlentities(stripslashes($issue['description']))); ?></p>
		</div>
		<form method="POST">
			<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
			<input type="submit" name="solved" value="Mark as Solved" />
		</form>			
		
		<div id="comments">
			<div id="comment-create">
				<h4>Post a comment</h4>
				<form method="POST">
					<input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>" />
					<textarea name="description" rows="5" cols="50"></textarea>
					<label></label>
					<input type="submit" name="createcomment" value="Comment" />
				</form>			
			</div>		
			<?php
			if (count($comments)>0) echo "<h3>Comments</h3>\n";
			foreach ($comments as $comment){
				echo "<div class='comment'><p>".str_replace("\n","<br />",htmlentities(stripslashes($comment['description'])))."</p><div><em>{$comment['user']}</em> on <em>{$comment['entrytime']}</em></div></div>\n";
			}
				
			?>

		
		
		</div>
	
	</div>
	<?php endif; ?>
	
<?php

?>

</div>
</body>
</html>
