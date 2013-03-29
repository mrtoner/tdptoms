<?php
/* At this time, the converter assumes the TDP and MS tables will 
   belong in the same database. */
define('DB','database');
define('HOST','localhost');
define('USER','user');
define('PWD','password');
define('TDP_PREFIX','tdp_');
define('MS_PREFIX','msp_');
define('TDP_ATTACH_PATH','/home/account/public_html/path/to/attachments/');

/* Use this setting to correct any time differences.
   Any offset must be in seconds */
define('TIME_OFFSET', 0);

/*
 * Convert TDP tickets and replies.
 */
try {
	echo '<pre>';
  $db = new PDO("mysql:dbname=". DB .";host=". HOST, USER, PWD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING) );

  $sql = 'SELECT * FROM '. TDP_PREFIX .'tickets ORDER BY ID';

  $new_ticket = array();
  foreach ($db->query($sql) as $ticket) {
    $department = convertDepartment($ticket['deptid']);
    $replies = getReplies($ticket['ticketid']);
    $priority = convertPriority($ticket['priority']);
    $createdDate = strtotime($ticket['ticketdate']) + TIME_OFFSET;
    $replyStatus = count($replies) ? getLastReplier($ticket) : 'start';
    $status = convertStatus($ticket['status']);
    $lastUpdate = getLastReplyDate($replies) != 0 ? strtotime(getLastReplyDate($replies)) + TIME_OFFSET : $createdDate;
    $comments = stripslashes($ticket['comments']);
    $comments = filter_comments($comments);
    
    $new_ticket = "INSERT INTO ". MS_PREFIX ."tickets (
                                            ts,
                                            lastrevision,
    																				department,
    																				assignedto,
    																				name,
    																				email,
    																				subject,
    																				mailBodyFilter,
    																				comments,
    																				priority,
    																				replyStatus,
    																				ticketStatus,
    																				ipAddresses,
    																				isDisputed,
    																				ticketNotes,
    																				tickLang,
    																				disPostPriv
    																			) 
    																		VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $values = array (
    									$createdDate,
    									$lastUpdate,
    									$department,
    									'',
    									stripslashes($ticket['name']),
    									$ticket['email'],
    									stripslashes($ticket['subject']),
    									'',
    									$comments,
    									$priority,
    									$replyStatus,
    									$status,
    									$ticket['ip'],
    									'no',
    									NULL,
    									'english',
    									'yes',
    								);

    $stmt = $db->prepare($new_ticket);
    $stmt->execute($values);
    $lastTicketID = $db->lastInsertId();
    echo 'Created ticket : ' . $ticket['subject'] . "<br />";

    foreach ($replies as $reply) {
      $repliedDate = strtotime($reply['posttime']) + TIME_OFFSET;
    	/* TDP has a bug (among many) that marks replies as 'admin' when merged.
    	   This will attempt to  fix that by marking all merged replies as 'visitor'.
    	   However, I really should compare the name on the reply to the list of users
    	   and mark only non-matching names as visitor. */
    	$isMerged = 'no';
    	if ($reply['responsetime'] == 'N/A. Ticket Merged') {
    	  $reply['adminreply'] = 0;
    	  $isMerged = 'yes';
    	}
    	$replyType = $reply['adminreply'] ? 'admin' : 'visitor';
    	$replyUser = $reply['adminreply'] ? convertUser($reply['name']) : 0;
    	$comments = stripslashes($reply['comments']);
      $comments = filter_comments($comments);

    	$new_reply = "INSERT INTO ". MS_PREFIX ."replies (
    																				ts,
    																				ticketID,
    																				comments,
    																				mailBodyFilter,
    																				replyType,
    																				replyUser,
    																				isMerged
    																			)
    																		VALUES (?,?,?,?,?,?,?)";
      $values = array(
                      $repliedDate,
                      $lastTicketID,
                      $comments,
                      '',
                      $replyType,
                      $replyUser,
                      $isMerged,
                    );

      $stmt = $db->prepare($new_reply);
      if (!$stmt) {
        $err = $sth->errorInfo();
        echo "<b style='color: red'>" . $err[2] . "</b><br />";
      }
      $stmt->execute($values);
      if (!$stmt) {
        $err = $sth->errorInfo();
        echo "<b style='color: red'>" . $err[2] . "</b><br />";
      }
      $lastReplyID = $db->lastInsertId();
      echo '&nbsp;&nbsp;&nbsp;Added ' . $replyType . ' reply ' . "<br />";
    }

        /* TDP only allows one attachment per ticket. */
    if ($ticket['attachstatus']) {
 			$path = TDP_ATTACH_PATH . $attachment['attachfile'];
 			if (file_exists($path)) {
	 			$filesize = filesize($path);
	    	$new_attachment = "INSERT INTO ". MS_PREFIX ."attachments (
	    	                                      ts,
	    																				ticketID,
	    																				replyID,
	    																				department,
	    																				fileName,
	    																				fileSize
	    																			)
	    																		VALUES (?,?,?,?,?,?)";
					$values = array(
	    										$replyDate,
	    										$lastTicketID,
	    										$lastReplyID,
	    										$department,
	    										$attachment['attachfile'],
	    										$filesize,
	    									);

	    		$stmt = $db->prepare($new_attachment);
	    		$stmt->execute($values);
	    		$lastAttachmentID = $db->lastInsertId();
    			echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Added attachment ' . $attachment['filename'] . "<br />";
	 			}
	 			
	 		}
  }
  echo '</pre>';
}

catch(PDOException $e) {
  echo $e->getMessage();
}

/*
 * Convert TDP standard responses.
 */
try {
	echo '<pre>';
  $db = new PDO("mysql:dbname=". DB .";host=". HOST, USER, PWD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING) );

  $sql = 'SELECT * FROM '. TDP_PREFIX .'responses ORDER BY ID';

  foreach ($db->query($sql) as $response) {
    $createdDate = strtotime($response['adddate']) + TIME_OFFSET;

    $new_response = "INSERT INTO ". MS_PREFIX ."responses (
                                            ts,
                                            title,
                                            answer,
    																				department,
    																				enResponse
    																			)
    																		VALUES (?,?,?,?,?)";

    $values = array (
    									$createdDate,
    									stripslashes($response['question']),
    									stripslashes($response['answer']),
    									0,
    									'yes',
    								);

    $stmt = $db->prepare($new_response);
    $stmt->execute($values);
    $lastResponseID = $db->lastInsertId();
    echo 'Created standard response : ' . $response['question'] . "<br />";
  }
  echo '</pre>';
}

catch(PDOException $e) {
  echo $e->getMessage();
}

function getReplies($ticketID) {
	try {
  	$db = new PDO("mysql:dbname=". DB .";host=". HOST, USER, PWD );
  
		$sth = $db->prepare('SELECT name,comments,posttime,responsetime,postdate,adminreply FROM '. TDP_PREFIX .'replies WHERE ticketid = :ticket');
		$sth->execute(array(':ticket' => $ticketID));
		$replies = $sth->fetchAll(PDO::FETCH_ASSOC);
		/* Need to sort replies, since merged tickets may appear out of order. */
		if (is_array($replies) && count($replies)) {
      foreach ($replies as $key => $value) {
        $sort[$key] = strtotime($value['posttime']);
        $name[$key] = $value['name'];
        $comment[$key] = $value['comments'];
        $posttime[$key] = $value['posttime'];
        $responsetime[$key] = $value['responsetime'];
        $adminreply[$key] = $value['adminreply'];
      }
      array_multisort($sort, SORT_ASC, $name, SORT_ASC, $comment, SORT_ASC, $posttime, SORT_ASC, $responsetime, SORT_ASC, $adminreply, SORT_ASC, $replies);
    }
	}
	
	catch(PDOException $e) {
	  echo $e->getMessage();
	}

	return $replies;
}

function getLastReplyDate($replies) {
	return isset($replies[count($replies)-1]['posttime']) ? $replies[count($replies)-1]['posttime'] : 0;
}

function getLastReplier($ticket) {
	return $ticket['responder'] ? 'visitor' : 'admin';
}

/* Assign Maian Support value to TDP key.
 * A real converter would have a GUI to map the departments. :)
 */
function convertDepartment($department = 1) {
  $departments_map = array(	1 => 1,
  												);
	return $departments_map[$department];
}

/* Assign Maian Support value to TDP key.
 * A real converter would have a GUI to map the ticket statuses. :)
 */
function convertPriority($priority = 2) {
  $priorities_map = array(	1 => 'low',
  													2 => 'medium',
  													3 => 'high',
  												);
	return $priorities_map[$priority];
}

/* Assign Maian Support value to TDP key. */
function convertStatus($ticket) {
	if ($ticket['status']) {
	  return 'open';
	}
	else {
	  if ($ticket['permaClose']) {
	    return 'closed';
	  }
	  else {
	    return 'close';
	  }
	}
}

/* Assign Maian Support value to TDP key.
 * TDP assigns tickets by name rather than by user ID.
 * A real converter would have a GUI to map the users. :)
 */
function convertUser($user = 0) {
	$users_map = array(	
											'Administrator' => 1,
									);
	return $users_map[$user];
}

/* Added this when I noticed that TDP encoded double quotes and MS doesn't.
   This also should be backported to version 1.0. */
function filter_comments($comments) {
  // return html_entity_decode($comments);
  // Nope, that does wacky stuff to diacriticals and such.
  return str_replace('&quot;', '"', $comments);
}
?>
