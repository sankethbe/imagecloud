<?php
session_start();
require_once 'Crypt/HMAC.php';    // grab this with "pear install Crypt_HMAC"
require_once 'HTTP/Request.php';  // grab this with "pear install --onlyreqdeps HTTP_Request"
require_once('class.s3.php');
require_once('simpledb.class.php');
require_once('sqs.client.php');


define('DOMAIN','photo_jobs'); //sdb
define('AWS_ACCESS_KEY_ID',		'');
define('AWS_SECRET_ACCESS_KEY',	'');


//1. Process GET vars
$BUCKET = $_SESSION['b'];
$OBJECT = $_SESSION['o'];

//2. Get Object/File from S3
$s3 = new S3();
$s3->deleteObject($OBJECT);
$s3->deleteBucket($BUCKET);


echo "<h1>Thank you</h1>";
echo "<p>Your files have now been cleaned up.</p>";
?>
