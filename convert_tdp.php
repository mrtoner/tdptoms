<?php
/* TDP, like Maian Support, saves the server time -- not GMT. */
define('TIME_OFFSET', 0);
define('STAMP_DATE', 'l, F j, Y | H:ia');

try {
	echo '<pre>';
  $db = new PDO("mysql:dbname=DBNAME;host=HOST", "USER", "PASSWORD" );

  $sql = 'SELECT * FROM tdp_tickets ORDER BY ID';

  $new_ticket = array();
  /* Iterate over each TDP ticket */
  foreach ($db->query($sql) as $ticket) {
    $department = convertDepartment($ticket['deptid']);
    $replies = getReplies($ticket['ticketid']);
    $priority = convertPriority($ticket['priority']);
    $createdDate = strtotime($ticket['ticketdate']) + TIME_OFFSET;
    $ticketStamp = date(STAMP_DATE, $createdDate);
    $replyStatus = count($replies) ? getLastReplier($ticket) : 'start';
    $status = convertStatus($ticket['status']);
    $addDate = date('Y-m-d', $createdDate);
    $addTime = date('G:i:s', $createdDate);
    $lastUpdate = date('Y-m-d', strtotime(getLastReplyDate($replies)) + TIME_OFFSET);
    
    $new_ticket = "INSERT INTO ms_tickets (
    																				department,
    																				name,
    																				email,
    																				subject,
    																				comments,
    																				priority,
    																				ticketStamp,
    																				replyStatus,
    																				ticketStatus,
    																				addDate,
    																				addTime,
    																				ipAddresses,
    																				lastUpdate,
    																				isDisputed,
    																				tickLang,
    																				disPostPriv
    																			) 
    																		VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $values = array (
    									$department,
    									stripslashes($ticket['name']),
    									$ticket['email'],
    									stripslashes($ticket['subject']),
    									stripslashes($ticket['comments']),
    									$priority,
    									$ticketStamp,
    									$replyStatus,
    									$status,
    									$addDate,
    									$addTime,
    									$ticket['ip'],
    									$lastUpdate,
    									'no',
    									'english',
    									'yes',
    								);

    $stmt = $db->prepare($new_ticket);
    $stmt->execute($values);
    $lastTicketID = $db->lastInsertId();
    echo 'Created ticket : ' . $ticket['subject'] . "<br />";

    foreach ($replies as $reply) {
      $repliedDate = strtotime($reply['posttime']) + TIME_OFFSET;
    	$replyDate = date('Y-m-d', $repliedDate);
    	$replyTime = date('G:i:s', $repliedDate);
    	$replyStamp = date(STAMP_DATE, $repliedDate);
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
    	$new_reply = "INSERT INTO ms_replies (
    																				ticketID,
    																				comments,
    																				addDate,
    																				addTime,
    																				replyStamp,
    																				replyType,
    																				replyUser,
    																				isMerged
    																			)
    																		VALUES (?,?,?,?,?,?,?,?)";
      $values = array(
                      $lastTicketID,
                      stripslashes($reply['comments']),
                      $replyDate,
                      $replyTime,
                      $replyStamp,
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
 			$path = 'PATH' . $attachment['attachfile'];
 			if (file_exists($path)) {
	 			$filesize = filesize($path);
	    	$new_attachment = "INSERT INTO ms_attachments (
	    																				ticketID,
	    																				replyID,
	    																				department,
	    																				fileName,
	    																				fileSize,
	    																				addDate
	    																			)
	    																		VALUES (?,?,?,?,?,?)";
					$values = array(
	    										$lastTicketID,
	    										$lastReplyID,
	    										$department,
	    										$attachment['attachfile'],
	    										$filesize,
	    										$replyDate,
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

function convertDepartment($department = 1) {
	/* Assign Maian Support value to TDP key */
  $departments_map = array(	12 => 11,
  													13 => 12,
  													14 => 6,
  													15 => 9,
  													16 => 2,
  													17 => 7,
  													18 => 10,
  													19 => 0,
  													20 => 1,
  													21 => 13,
  													22 => 8,
  													23 => 4,
  													24 => 3,
  													25 => 5,
  												);
	return $departments_map[$department];
}

function getReplies($ticketID) {
	try {
  	$db = new PDO("mysql:dbname=DBNAME;host=HOST", "USER", "PASSWORD" );
  
		$sth = $db->prepare('SELECT name,comments,posttime,responsetime,postdate,adminreply FROM tdp_replies WHERE ticketid = :ticket');
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

function getFirstReply($replies, $key) {
	return $key == 'timestamp' ? $replies[0]['timestamp'] : $replies[0]['message'];
}

function getLastReplyDate($replies) {
	return isset($replies[count($replies)-1]['posttime']) ? 'admin' : 'visitor';
}

function getLastReplier($ticket) {
	return $ticket['responder'] ? 'visitor' : 'admin';
}

function convertPriority($priority = 2) {
	/* Assign Maian Support value to TDP key */
  $priorities_map = array(	1 => 'low',
  													2 => 'medium',
  													3 => 'high',
  												);
	return $priorities_map[$priority];
}

function convertStatus($ticket) {
	/* Assign Maian Support value to TDP key */
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

function convertUser($user = 0) {
	/* Assign Maian Support value to TDP key */
	/* TDP assigns tickets by name rather than by user ID. */
	$users_map = array(	
											'Don Morris' => 1,
									);
	return $users_map[$user];
}
?>
