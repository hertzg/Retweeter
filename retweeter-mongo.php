<?php
/*
 ======================================================================
 LICENSE

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>
 ======================================================================
*/


// Set username - which is also the hashtag retweeter will look for
$username = 'username';

// Setup database connection
$mongoConnStr = 'mongodb://127.0.0.1:27017';

// we'll need some OAuth stuff here
// register your retweeter at http://dev.twitter.com/apps/new
$consumer_key = 'nmPWI7EsAlGPgXjtczXIAA';  
$consumer_request = '1RZfeQ00ZhltyhHVQOP3kcCDZBAK4xndUiwuNCrFYOs';
  
// then click on "my token" on the resulting page and get these (make sure 
// you are logged in AS THE USERNAME you intend to use, as these keys are 
// specific to the user:  
$retweeter_oauth_token = '618467286-JPnVa31o92plDnnI1rhUkSU8fWbEZcM6t9iJtlb5';
$retweeter_oauth_secret = 'haPiUDTpvNb67hArtrYCpZrLIm7wdTXYpSOrLgxWt0o';

// To use the old format rather than the new retweet API, change this to true  
define('USE_OLD_FORMAT',false);   
  
// most users should not have to config beyond here
require_once('twitteroauth.php');

$MO = new MongoClient($mongoConnStr);
$DB = $MO->retweeter;

// get the md5 hash from db or make it if it doesn't exist
$res = $DB->conf->findOne(array('key'=>'hash'));

if($res) {
	$oauth_hash = $res['value'];
} else {
	$oauth_hash = md5($consumer_key.$consumer_request.$retweeter_oauth_token.$retweeter_oauth_secret);
	$DB->conf->insert(array('key'=>'hash', 'value'=>trim($oauth_hash)));
}

$connection = new TwitterOAuth(
                                 $consumer_key, 
                                 $consumer_request, 
                                 $retweeter_oauth_token, 
                                 $retweeter_oauth_secret
                                 );
  
  
// The twitter API address
$url = 'http://twitter.com/statuses/friends_timeline.xml';

$buffer = $connection->get($url);
  
// check for success or failure
if (empty($buffer)) { echo 'got no data'; } else {
	$responseCode = $connection->http_code;
}
			
// Log status here
$myResponseCode = (string)($responseCode);
$DB->log->insert(array('status' => $myResponseCode));

if ($responseCode == 200)
{
	$xml = new SimpleXMLElement($buffer);

	foreach( $xml->status as $twTweetNode)
	{
		$strTweet = $twTweetNode->text; 
		$strPostId = $twTweetNode->user->id . $twTweetNode->id;
		$strUser = $twTweetNode->user->screen_name;
		$strPlainPostId = $twTweetNode->id;
		
		//echo $strPostId . " " . $strUser . " ";

		// Since we're using Friends_timeline, need to strip out the user			
		if (strtolower($strUser) != strtolower($username))		
		{			
			$insert = 0;
			$result = $DB->tweet->findOne(array('postId'=>(string)$strPostId));

			if (!$result) 
			{
				$insert = 1;
			}
			
			// set hashtag and tweet to lower for case-insensitive comparison
			$myHashtag = "#" . strtolower($username); 
			if ((strpos(strtolower($strTweet),$myHashtag) > -1) && $insert == 1) 
			{
				$myTweet = mysql_real_escape_string($strTweet,$db_handle);
				
				$DB->tweet->insert(array(
					'postId' => trim($strPostId),
					'user' => trim($strUser),
					'tweet' => trim($myTweet),
					'plainPostId' => trim($strPlainPostId),
					'tweeted' => null
				));

			}
		} // end if for != $username

	} // end for each status
} else {
	echo '<p>Getting tweets failed, with status code ' . $responseCode . '</p>';
	echo '<p>Entire response was: '. print_r($buffer,true) .'</p>';
}// end if Status Code 200
		
// Now we'll go and check the db for tweets which have not yet been retweeted

$cursor = $DB->tweet->find(array(
	'postId' => null
));


// date for tweeted
$mysqldate = time();

// look at each un-retweeted tweet, post it, and set Tweeted date	
foreach($cursor as $_id => $obj) {
	if($obj['plainPostId'] == '' || USE_OLD_FORMAT) {
		$myTweetUser = $row['user'];
		$myTweetText = $row['tweet'];
		
		if( (strlen($myTweetText) + strlen($myTweetUser) + strlen("rt: @ ") ) > 138) {
			// Houston, we have a problem - this will be too big when retweeted
			$myTweetArray =  explode("\n", wordwrap($myTweetText,132-strlen($myTweetUser) ) );
			$myTweetText = $myTweetArray[0]  . " ..." ;	
		}
		
	    $myTweet = "rt: @" . $myTweetUser . " " . $myTweetText; 
		$tweet_post_url = 'http://twitter.com/statuses/update.xml';	
		$buffer = $connection->post($tweet_post_url,array(
			'status' => $myTweet,
			'source' => 'retweeter'
		));
	} else { // tweet has a plain id, can use retweet api
		$myTweet = $myTweet = $obj['plainPostId']; 
		$tweet_post_url = 'http://api.twitter.com/1/statuses/retweet/' . $myTweet . '.xml';	
		$buffer = $connection->post($tweet_post_url);
	}
	
	// If it fails, don't mark it Tweeted, we'll get it next time
	if (empty($buffer)) { 
		echo 'got no data'; 
		$responseCode = '';
	} else {
		$responseCode = $connection->http_code;
	}
	
	if ($responseCode == 200) {
		echo 're-tweeted one';
		$DB->tweet->update(array('postId'=>$obj['postId']), array('tweeted'=>$mysqldate));
	} else {
		echo '<p>Re-tweet failed, with status code ' . $responseCode . '</p>';
		echo '<p>Entire response was: '. print_r($buffer,true) .'</p>';
	}
}
?>
