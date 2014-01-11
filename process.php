<?php

ini_set('display_errors', TRUE);

require_once 'Crypt/HMAC.php';    // grab this with "pear install Crypt_HMAC"
require_once 'HTTP/Request.php';  // grab this with "pear install --onlyreqdeps HTTP_Request"
require_once('class.s3.php');
require_once('simpledb.class.php');
require_once('sqs.client.php');

define('ROOT', dirname(realpath(__FILE__)) . '/');

require ROOT . 'instagraph.php';

define('AWS_ACCESS_KEY_ID',		'');
define('AWS_SECRET_ACCESS_KEY',	'');
define('SQS_ENDPOINT',	'http://queue.amazonaws.com');
define('DOMAIN','photo_jobs'); //sdb
define('SQS_Q','photo_q'); //sqs


//1. Fire up SQS and get next message off queue
$q = new SQSClient(AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, SQS_ENDPOINT, SQS_Q);

$nextMessage = $q->ReceiveMessage(1);

print_r($nextMessage);

if (count($nextMessage)){

foreach($nextMessage as $message){
	//grab the message body for use in lookup on SimpleDB
	$BUCKET = urldecode($message->Body);
	$handle = $message->ReceiptHandle;
}

echo "Bucket ID=" . $BUCKET . "<br/>";

//2. login to simpledb
$sd = new SimpleDb(AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY);


//3. Pull out attributes we need!
$domains = $sd->listDomains();
foreach ($domains->ListDomainsResult as $domains){
	foreach ($domains as $id => $d_name){
		if ($d_name == DOMAIN){
			$mydomain = $sd->query($d_name);
			//print_r($mydomain);
			foreach ($mydomain->QueryResult as $items){
				foreach ($items as $itemid => $i_name){
					if ($i_name == $BUCKET){
						$attr = $sd->getAttributes($d_name,$i_name);
					}				
				}
			}
		}
	}
}


//4. Process attribute data
foreach ($attr->GetAttributesResult as $attribute){
	foreach ($attribute as $array){
		//echo $array->Name . ":". $array->Value ."<br/>";
		if ($array->Name == "email"){
			$EMAIL = $array->Value;
		}
		if ($array->Name == "file"){
			$OBJECT = $array->Value;
		}		

		if ($array->Name == "filter"){
			$FILTER = $array->Value;
		}
	}
}


//5. Get Object/File from S3
$s3 = new S3();
$s3->downloadObject($BUCKET,$OBJECT,"/var/www/html/images/input/".$OBJECT);

//6. Update Status in SimpleDB
$data["status"]		=	array('working');
$sd->putAttributes(DOMAIN,$BUCKET,$data);


//7. Process image

  $input = '/var/www/html/images/input/' . $OBJECT;
  $output = '/var/www/html/images/output/' . $OBJECT;

  //$filter = 'TiltShift';

  $instagraph = new instagraph;
  $instagraph->setInput($input);
  $instagraph->setOutput($output);
  $instagraph->process($FILTER);

error_log('image processed');

//8. Finalize status & upload thumbnail
$data["status"]		=	array('done');
$sd->putAttributes(DOMAIN,$BUCKET,$data);

$upload = $s3->putObject( $BUCKET, $OBJECT, '/var/www/html/images/output/'.$OBJECT, true);
$URL = "http://ec2-54-204-212-149.compute-1.amazonaws.com/retrieve.php?b=".$BUCKET."&o=".urlencode($OBJECT);
echo "<a href='$URL'>Get image</a>";

//9. Send email
$to = $EMAIL;
$from = 'image-processor@ec2-54-204-212-149.compute-1.amazonaws.com';
$subj = "Image is ready!";
$msg = "Your image ($OBJECT) is ready.\r\n To retrieve the processed image, please go to:\r\n ". $URL. "\r\n";
mail($to, $subj,$msg, "From:$from\r\n");


//10. Delete message from queue
$q->DeleteMessage($handle);

}//end if count($nextMessage)

//9. Redirect
$location="Location:".$URL;
header($location);
?>
