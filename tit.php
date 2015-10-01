<?php
/*
 *      Tiny Issue Tracker (TIT) v2.0
 *      SQLite based, single file Issue Tracker
 *
 *      Copyright 2010-2013 Jwalanta Shrestha <jwalanta at gmail dot com>
 *      GNU GPL
 */

///////////////////
// CONFIGURATION //
///////////////////

if (!defined("TIT_INCLUSION"))
{
	$TITLE = "My Project";              // Project Title
	$EMAIL = "noreply@example.com";     // "From" email address for notifications

	// Array of users.
	// Mandatory fields: username, password (md5 hash)
	// Optional fields: email, admin (true/false)

	$USERS = array(
		array("username"=>"admin","password"=>md5("admin"),"email"=>"admin@example.com","admin"=>true),
		array("username"=>"user" ,"password"=>md5("user") ,"email"=>"user@example.com"),
	);

	// PDO Connection string ()
	// eg, SQlite: sqlite:<filename> (Warning: if you're upgrading from an earlier version of TIT, you have to use "sqlite2"!)
	//     MySQL: mysql:dbname=<dbname>;host=<hostname>
	$DB_CONNECTION = "sqlite:tit.db";
	$DB_USERNAME = "";
	$DB_PASSWORD = "";

	// Select which notifications to send
	$NOTIFY["ISSUE_CREATE"]     = TRUE;     // issue created
	$NOTIFY["ISSUE_EDIT"]       = TRUE;     // issue edited
	$NOTIFY["ISSUE_DELETE"]     = TRUE;     // issue deleted
	$NOTIFY["ISSUE_STATUS"]     = TRUE;     // issue status change (solved / unsolved)
	$NOTIFY["ISSUE_PRIORITY"]   = TRUE;     // issue status change (solved / unsolved)
	$NOTIFY["COMMENT_CREATE"]   = TRUE;     // comment post

	// Modify this issue types
	$STATUSES = array(0 => "Active", 1 => "Resolved");
}
////////////////////////////////////////////////////////////////////////
////// DO NOT EDIT BEYOND THIS IF YOU DON'T KNOW WHAT YOU'RE DOING /////
////////////////////////////////////////////////////////////////////////

if (get_magic_quotes_gpc()){
	foreach($_GET  as $k=>$v) $_GET [$k] = stripslashes($v);
	foreach($_POST as $k=>$v) $_POST[$k] = stripslashes($v);
}

// Here we go...
session_start();

// check for login post
$message = "";
if (isset($_POST["login"])){
	$n = check_credentials($_POST["u"],md5($_POST["p"]));
	if ($n>=0){
		$_SESSION['tit']=$USERS[$n];

		header("Location: ".$_SERVER["REQUEST_URI"]);
	}
	else $message = "Invalid username or password";
}

// check for logout
if (isset($_GET['logout'])){
	$_SESSION['tit']=array();  // username
	header("Location: ".$_SERVER["REQUEST_URI"]);
}

$login_html = "<html><head><title>Tiny Issue Tracker</title><style>body,input{font-family:sans-serif;font-size:11px;} label{display:block;}</style></head>
							 <body><h2>$TITLE - Issue Tracker</h2><p>$message</p><form method='POST' action='".$_SERVER["REQUEST_URI"]."'>
							 <label>Username</label><input type='text' name='u' />
							 <label>Password</label><input type='password' name='p' />
							 <label></label><input type='submit' name='login' value='Login' />
							 </form></body></html>";

// show login page on bad credential
if (check_credentials($_SESSION['tit']['username'], $_SESSION['tit']['password'])==-1) die($login_html);

// Check if db exists
try{$db = new PDO($DB_CONNECTION, $DB_USERNAME, $DB_PASSWORD);}
catch (PDOException $e) {die("DB Connection failed: ".$e->getMessage());}

// create tables if not exist
@$db->exec("CREATE TABLE issues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, user TEXT, status INTEGER NOT NULL DEFAULT '0', priority INTEGER, notify_emails TEXT, entrytime DATETIME)");
@$db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, description TEXT, entrytime DATETIME)");

if (isset($_GET["id"])){
	// show issue #id
	$id=pdo_escape_string($_GET['id']);
	$issue = $db->query("SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE id='$id'")->fetchAll();
	$comments = $db->query("SELECT id, user, description, entrytime FROM comments WHERE issue_id='$id' ORDER BY entrytime ASC")->fetchAll();
}

// if no issue found, go to list mode
if (count($issue)==0){

	unset($issue, $comments);
	// show all issues

	$status = 0;
	if (isset($_GET["status"]))
		$status = (int)$_GET["status"];

	$issues = $db->query(
		"SELECT id, title, description, user, status, priority, notify_emails, entrytime, comment_user, comment_time ".
		" FROM issues ".
		" LEFT JOIN (SELECT max(entrytime) as max_comment_time, issue_id FROM comments GROUP BY issue_id) AS cmax ON cmax.issue_id = issues.id".
		" LEFT JOIN (SELECT user AS comment_user, entrytime AS comment_time, issue_id FROM comments ORDER BY issue_id DESC, entrytime DESC) AS c ON c.issue_id = issues.id AND cmax.max_comment_time = c.comment_time".
		" WHERE status=".pdo_escape_string($status ? $status : "0 or status is null"). // <- this is for legacy purposes only
		" ORDER BY priority, entrytime DESC")->fetchAll();

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

	$id=pdo_escape_string($_POST['id']);
	$title=pdo_escape_string($_POST['title']);
	$description=pdo_escape_string($_POST['description']);
	$priority=pdo_escape_string($_POST['priority']);
	$user=pdo_escape_string($_SESSION['tit']['username']);
	$now=date("Y-m-d H:i:s");

	// gather all emails
	$emails=array();
	for ($i=0;$i<count($USERS);$i++){
		if ($USERS[$i]["email"]!='') $emails[] = $USERS[$i]["email"];
	}
	$notify_emails = implode(",",$emails);

	if ($id=='')
		$query = "INSERT INTO issues (title, description, user, priority, notify_emails, entrytime) values('$title','$description','$user','$priority','$notify_emails','$now')"; // create
	else
		$query = "UPDATE issues SET title='$title', description='$description' WHERE id='$id'"; // edit

	if (trim($title)!='') {     // title cant be blank
		@$db->exec($query);
		if ($id==''){
			// created
			$id=$db->lastInsertId();
			if ($NOTIFY["ISSUE_CREATE"])
				notify( $id,
								"[$TITLE] New Issue Created",
								"New Issue Created by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
		}
		else{
			// edited
			if ($NOTIFY["ISSUE_EDIT"])
				notify( $id,
								"[$TITLE] Issue Edited",
								"Issue edited by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");
		}
	}

	header("Location: {$_SERVER['PHP_SELF']}");
}

// Delete issue
if (isset($_GET["deleteissue"])){
	$id=pdo_escape_string($_GET['id']);
	$title=get_col($id,"issues","title");

	// only the issue creator or admin can delete issue
	if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username']==get_col($id,"issues","user")){
		@$db->exec("DELETE FROM issues WHERE id='$id'");
		@$db->exec("DELETE FROM comments WHERE issue_id='$id'");

		if ($NOTIFY["ISSUE_DELETE"])
			notify( $id,
							"[$TITLE] Issue Deleted",
							"Issue deleted by {$_SESSION['tit']['username']}\r\nTitle: $title");
	}
	header("Location: {$_SERVER['PHP_SELF']}");

}

// Change Priority
if (isset($_GET["changepriority"])){
	$id=pdo_escape_string($_GET['id']);
	$priority=pdo_escape_string($_GET['priority']);
	if ($priority>=1 && $priority<=3) @$db->exec("UPDATE issues SET priority='$priority' WHERE id='$id'");

	if ($NOTIFY["ISSUE_PRIORITY"])
		notify( $id,
						"[$TITLE] Issue Priority Changed",
						"Issue Priority changed by {$_SESSION['tit']['username']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// change status
if (isset($_GET["changestatus"])){
	$id=pdo_escape_string($_GET['id']);
	$status=pdo_escape_string($_GET['status']);
	@$db->exec("UPDATE issues SET status='$status' WHERE id='$id'");

	if ($NOTIFY["ISSUE_STATUS"])
		notify( $id,
						"[$TITLE] Issue Marked as ".$STATUSES[$status],
						"Issue marked as {$STATUSES[$status]} by {$_SESSION['u']}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id");

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Unwatch
if (isset($_POST["unwatch"])){
	$id=pdo_escape_string($_POST['id']);
	setWatch($id,false);       // remove from watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Watch
if (isset($_POST["watch"])){
	$id=pdo_escape_string($_POST['id']);
	setWatch($id,true);         // add to watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}


// Create Comment
if (isset($_POST["createcomment"])){

	$issue_id=pdo_escape_string($_POST['issue_id']);
	$description=pdo_escape_string($_POST['description']);
	$user=$_SESSION['tit']['username'];
	$now=date("Y-m-d H:i:s");

	if (trim($description)!=''){
		$query = "INSERT INTO comments (issue_id, description, user, entrytime) values('$issue_id','$description','$user','$now')"; // create
		$db->exec($query);
	}

	if ($NOTIFY["COMMENT_CREATE"])
		notify( $id,
						"[$TITLE] New Comment Posted",
						"New comment posted by {$user}\r\nTitle: ".get_col($id,"issues","title")."\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$issue_id");

	header("Location: {$_SERVER['PHP_SELF']}?id=$issue_id");

}

// Delete Comment
if (isset($_GET["deletecomment"])){
	$id=pdo_escape_string($_GET['id']);
	$cid=pdo_escape_string($_GET['cid']);

	// only comment poster or admin can delete comment
	if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username']==get_col($cid,"comments","user"))
		$db->exec("DELETE FROM comments WHERE id='$cid'");

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

//
//      FUNCTIONS
//

// PDO quote, but without enclosing single-quote
function pdo_escape_string($str){
	global $db;
	$quoted = $db->quote($str);
	return ($db->quote("")=="''")?substr($quoted, 1, strlen($quoted)-2):$quoted;
}

// check credentials, returns -1 if not okay
function check_credentials($u, $p){
	global $USERS;

	$n=0;
	foreach ($USERS as $user){
		if (strcasecmp($user['username'],$u)===0 && $user['password']==$p) return $n;
		$n++;
	}
	return -1;
}

// get column from some table with $id
function get_col($id, $table, $col){
	global $db;
	$result = $db->query("SELECT $col FROM $table WHERE id='$id'")->fetchAll();
	return $result[0][$col];
}

// notify via email
function notify($id, $subject, $body){
	global $db;
	$result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
	$to = $result[0]['notify_emails'];

	if ($to!=''){
		global $EMAIL;
		$headers = "From: $EMAIL" . "\r\n" . 'X-Mailer: PHP/' . phpversion();

		mail($to, $subject, $body, $headers);       // standard php mail, hope it passes spam filter :)
	}

}

// start/stop watching an issue
function watchFilterCallback($email) { return $email != $_SESSION['tit']['email']; }

function setWatch($id,$addToWatch){
	global $db;
	if ($_SESSION['tit']['email']=='') return;

	$result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
	$notify_emails = $result[0]['notify_emails'];

	$emails = $notify_emails ? explode(",",$notify_emails) : array();

	if ($addToWatch) $emails[] = $_SESSION['tit']['email'];
	else $emails = array_filter( $emails, "watchFilterCallback" );
	$emails = array_unique($emails);

	$notify_emails = implode(",",$emails);

	$db->exec("UPDATE issues SET notify_emails='$notify_emails' WHERE id='$id'");
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title><?php echo $TITLE, isset($_GET["id"]) ? (" - #".$_GET["id"]) : "" , " - Issue Tracker"; ?></title>
	<meta http-equiv="content-type" content="text/html;charset=utf-8" />
	<style>
		html { overflow-y: scroll;}
		body { font-family: sans-serif; font-size: 11px; background-color: #aaa;}
		a, a:visited{color:#004989; text-decoration:none;}
		a:hover{color: #666; text-decoration: underline;}
		label{ display: block; font-weight: bold;}
		table{border-collapse: collapse;}
		th{text-align: left; background-color: #f2f2f2;}
		tr:hover{background-color: #f0f0f0;}
		#menu{float: right;}
		#container{width: 760px; margin: 0 auto; padding: 20px; background-color: #fff;}
		#footer{padding:10px 0 0 0; margin-top: 20px; text-align: center; border-top: 1px solid #ccc;}
		#create{padding: 15px; background-color: #f2f2f2;}
		.issue{padding:10px 20px; margin: 10px 0; background-color: #f2f2f2;}
		.comment{padding:5px 10px 10px 10px; margin: 10px 0; border: 1px solid #ccc;}
		.comment:target{outline: 2px solid #444;}
		.comment-meta{color: #666;}
		.p1, .p1 a{color: red;}
		.p3, .p3 a{color: #666;}
		.hide{display:none;}
		.left{float: left;}
		.right{float: right;}
		.clear{clear:both;}
	</style>
</head>
<body>
<div id='container'>
	<div id="menu">
		<?php
			foreach($STATUSES as $code=>$name) {
				$style=(isset($_GET[status]) && $_GET[status]==$code) || (isset($issue) && $issue['status']==$code)?"style='font-weight:bold;'":"";
				echo "<a href='{$_SERVER['PHP_SELF']}?status={$code}' alt='{$name} Issues' $style>{$name} Issues</a> | ";
			}
		?>
		<a href="<?php echo $_SERVER['PHP_SELF']; ?>?logout" alt="Logout">Logout [<?php echo $_SESSION['tit']['username']; ?>]</a>
	</div>

	<h1><?php echo $TITLE; ?></h1>

	<h2><a href="#" onclick="document.getElementById('create').className='';document.getElementById('title').focus();"><?php echo ($issue['id']==''?"Create":"Edit"); ?> Issue <?php echo $issue['id'] ?></a></h2>
	<div id="create" class='<?php echo isset($_GET['editissue'])?'':'hide'; ?>'>
		<a href="#" onclick="document.getElementById('create').className='hide';" style="float: right;">[Close]</a>
		<form method="POST">
			<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
			<label>Title</label><input type="text" size="50" name="title" id="title" value="<?php echo htmlentities($issue['title']); ?>" />
			<label>Description</label><textarea name="description" rows="5" cols="50"><?php echo htmlentities($issue['description']); ?></textarea>
			<label></label><input type="submit" name="createissue" value="<?php echo ($issue['id']==''?"Create":"Edit"); ?>" />
<? if (!$issue['id']) { ?>
			Priority
				<select name="priority">
					<option value="1">High</option>
					<option selected value="2">Medium</option>
					<option value="3">Low</option>
				</select>
<? } ?>
		</form>
	</div>

	<?php if ($mode=="list"): ?>
	<div id="list">
	<h2><?php if (isset($STATUSES[$_GET['status']])) echo $STATUSES[$_GET['status']]." "; ?>Issues</h2>
		<table border=1 cellpadding=5 width="100%">
			<tr>
				<th>ID</th>
				<th>Title</th>
				<th>Created by</th>
				<th>Date</th>
				<th><acronym title="Watching issue?">W</acronym></th>
				<th>Last Comment</th>
				<th>Actions</th>
			</tr>
			<?php
			$count=1;
			foreach ($issues as $issue){
				$count++;
				echo "<tr class='p{$issue['priority']}'>\n";
				echo "<td>{$issue['id']}</a></td>\n";
				echo "<td><a href='?id={$issue['id']}'>".htmlentities($issue['title'],ENT_COMPAT,"UTF-8")."</a></td>\n";
				echo "<td>{$issue['user']}</td>\n";
				echo "<td>{$issue['entrytime']}</td>\n";
				echo "<td>".($_SESSION['tit']['email']&&strpos($issue['notify_emails'],$_SESSION['tit']['email'])!==FALSE?"&#10003;":"")."</td>\n";
				echo "<td>".($issue['comment_user'] ? date("M j",strtotime($issue['comment_time'])) . " (" . $issue['comment_user'] . ")" : "")."</td>\n";
				echo "<td><a href='?editissue&id={$issue['id']}'>Edit</a>";
				if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username']==$issue['user']) echo " | <a href='?deleteissue&id={$issue['id']}' onclick='return confirm(\"Are you sure? All comments will be deleted too.\");'>Delete</a>";
				echo "</td>\n";
				echo "</tr>\n";
			}
			?>
		</table>
	</div>
	<?php endif; ?>

	<?php if ($mode=="issue"): ?>
	<div id="show">
		<div class="issue">
			<h2><?php echo htmlentities($issue['title'],ENT_COMPAT,"UTF-8"); ?></h2>
			<p><?php echo nl2br( preg_replace("/([a-z]+:\/\/\S+)/","<a href='$1'>$1</a>", htmlentities($issue['description'],ENT_COMPAT,"UTF-8") ) ); ?></p>
		</div>
		<div class='left'>
			Priority <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changepriority&id=<?php echo $issue['id']; ?>&priority='+this.value">
				<option value="1"<?php echo ($issue['priority']==1?"selected":""); ?>>High</option>
				<option value="2"<?php echo ($issue['priority']==2?"selected":""); ?>>Medium</option>
				<option value="3"<?php echo ($issue['priority']==3?"selected":""); ?>>Low</option>
			</select>
			Status <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changestatus&id=<?php echo $issue['id']; ?>&status='+this.value">
			<?php foreach($STATUSES as $code=>$name): ?>
				<option value="<?php echo $code; ?>"<?php echo ($issue['status']==$code?"selected":""); ?>><?php echo $name; ?></option>
			<?php endforeach; ?>
			</select>
		</div>
		<div class='left'>
			<form method="POST">
				<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
				<?php
					if ($_SESSION['tit']['email']&&strpos($issue['notify_emails'],$_SESSION['tit']['email'])===FALSE)
						echo "<input type='submit' name='watch' value='Watch' />\n";
					else
						echo "<input type='submit' name='unwatch' value='Unwatch' />\n";
				?>
			</form>
		</div>
		<div class='clear'></div>
		<div id="comments">
			<?php
			if (count($comments)>0) echo "<h3>Comments</h3>\n";
			foreach ($comments as $comment){
				echo "<div class='comment' id='c".$comment['id']."'><p>".nl2br( preg_replace("/([a-z]+:\/\/\S+)/","<a href='$1'>$1</a>",htmlentities($comment['description'],ENT_COMPAT,"UTF-8") ) )."</p>";
				echo "<div class='comment-meta'><em>{$comment['user']}</em> on <em><a href='#c".$comment['id']."'>{$comment['entrytime']}</a></em> ";
				if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username']==$comment['user']) echo "<span class='right'><a href='{$_SERVER['PHP_SELF']}?deletecomment&id={$issue['id']}&cid={$comment['id']}' onclick='return confirm(\"Are you sure?\");'>Delete</a></span>";
				echo "</div></div>\n";
			}
			?>
			<div id="comment-create">
				<h4>Post a comment</h4>
				<form method="POST">
					<input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>" />
					<textarea name="description" rows="5" cols="50"></textarea>
					<label></label>
					<input type="submit" name="createcomment" value="Comment" />
				</form>
			</div>
		</div>
	</div>
	<?php endif; ?>
	<div id="footer">
		Powered by <a href="https://github.com/jwalanta/tit" alt="Tiny Issue Tracker" target="_blank">Tiny Issue Tracker</a>
	</div>
</div>
</body>
</html>
