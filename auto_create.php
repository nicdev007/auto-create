<?php
/* auto_create.php - Contains all the plugins for the auto create function
 * @ author Nicolaas du Toit
 * 
 */

$PLUGININFO['auto_create']['version'] = 1.1;
$PLUGININFO['auto_create']['description'] = 'Auto create incidents';
$PLUGININFO['auto_create']['author'] = 'Nicolaas du Toit';
$PLUGININFO['auto_create']['legal'] = 'legal';
$PLUGININFO['auto_create']['sitminversion'] = 3.45;
$PLUGININFO['auto_create']['sitmaxversion'] = 3.60;

plugin_register('email_arrived', 'auto_create_incidents');

require_once (APPLICATION_PLUGINPATH.'auto_create'.DIRECTORY_SEPARATOR.'functions_auto_create.php');
include (APPLICATION_PLUGINPATH.'auto_create'.DIRECTORY_SEPARATOR.'config_auto_plugin.php');
require_once (APPLICATION_FSPATH.DIRECTORY_SEPARATOR.'core.php');

/**
 * The main "auto_create" function
 * The function is specific to rules sent to our clients regarding the naming of
 *  their email Subject:
 * For this reason the plugin is specific but not impossible to adapt
 * Clients send their email like this:
 * "Hardware [Productname] Serial number Reference"
 * or "Software [Softwarename] Version Reference"
 * or "Warranty [Productname] Serial number Reference"
 * This helps to simplify the creation, and detection of this function
 * @author Nico du toit
 * @return Returns the newly created incidentid to the inbopund script to
 * continue processing
 * OR returns nothing and the new update goes to the holding queue as before
 */

function auto_create_incidents($params) {
 unset ($GLOBALS['plugin_reason']);
 $incidentid = $params['incidentid'];
 $contactid = $params['contactid'];
 $subject = $params['subject'];
 $decoded = $params['decoded'];
 $send_email = 1;
 global $CONFIG, $dbIncidents, $now;
 debug_log("incident ID : ".$incidentid." \n  Contactid:  ".$contactid."\n"."Subject : ".$subject);

 if ($incidentid > 0) {
  return $incidentid;
 }

 if (in_array($contactid, $CONFIG['auto_create_contact_exclude'])) {
  debug_log("For this client : ". $contactid." autocreate is forbidden! see the config file");
  $GLOBALS['plugin_reason'] = 'Contact BLOCKED';
  return;
 }

 if ($contactid < 1) {
  $GLOBALS['plugin_reason'] = 'Contact not in DataBase';
  return;
 }

 $cc = find_cc_decoded($decoded);
 if (stristr($cc, $CONFIG['support_email'])) {
  debug_log("Support was in the copy of the email!!");
  $GLOBALS['plugin_reason'] = 'Support in CC of email';
  return;
 }

 debug_log("Redirecting to function checking for duplicates ... ");

 //Check if duplicates exists in the incidents DB
 $create_incident = check_for_duplicates($subject, $contactid);

 if($create_incident == "NO") {
  debug_log("case 0:   more than 1 duplicate");
  $GLOBALS['plugin_reason'] = 'Possible DUPLICATE';
  return;
 }
 if ($create_incident !=  "YES" && $create_incident !="NO") {
  debug_log("case 1:   only 1 duplicate");
  debug_log("The duplicate incidentID =: ".$create_incident);
  return $create_incident;
 }
 if ($create_incident == "YES") {
  debug_log("case 2:   No duplicates found");
  debug_log("Proceeding to - Auto create");

  $ccemail = $cc;
  $origsubject = mysql_real_escape_string($subject);
  $subject = strtolower ($subject);

  //Check if any of our keywords exists TODO: Need to make the keywords
  //configurable in the config file
  $warranty = preg_match("/warranty|warrantee|waranty|diagnostic/i", $subject);
  $hard = preg_match("/hardware|hard/i", $subject);
  $soft = preg_match("/soft|software/i", $subject);
  
  if ($warranty == 1 && $hard == 0 && $soft == 0) {
   $case = 0;
   debug_log("Match for Warranty in : ".$subject);
  }

  elseif ($hard == 1 && $soft == 0 && $warranty == 0) {
   $case = 1;
   debug_log("Match for Hardware in : ".$subject);
  }

  elseif ($soft == 1 && $hard == 0 && $warranty == 0) {
   $case = 2;
   debug_log("Match for Software in : ".$subject);
  }

  else {
   $case = 3;
   debug_log("NO match found for any keyword in : ".$subject);
  }

  switch ($case) {

   case 0:
    debug_log("Case type Warranty : ");
    $product = 9;//Hardware
    //$software = 41;//Warranty clam
    $software = find_tags_in_subject($subject);
    debug_log("The software tag returned is : ".$software);
    if (!$software || !(in_array($software, $CONFIG['auto_create_warranty_include']))){
     debug_log("The soft is not in the accepted warranty list or is 0 :");
     $software = 41;//if no tags are found use the normal warranty claim
    }

    $servicelevel = $CONFIG['default_service_level'];

    $siteid = contact_siteid($contactid);

    $sql = "SELECT id FROM `sit_maintenance` WHERE site='{$siteid}' AND product='{$product}' ";
    $result = mysql_query($sql);
    if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);
    $row = mysql_fetch_row($result);
    $contrid = $row[0];
    //Create the incident based on the info we have
    $incidentid='';
    $incidentid = create_incident($origsubject, $contactid, $servicelevel, $contrid, $product,
        $software, $priority = 1, $owner = 0, $status = 1,
        $productversion = '', $productservicepacks = '',
        $opened = '', $lastupdated = '');

    debug_log("Incident ID created : ".$incidentid);
    debug_log("CC address(es) found : ".$ccemail);

    //If we have some cc addresses, then we can update them into the case
    if ($ccemail) {
     $sql = "UPDATE `{$dbIncidents}` ";
     $sql .= "SET ccemail='$ccemail', lastupdated='$now' WHERE id='$incidentid'";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     if (!$result) debug_log("Update to the incident cc email succesfull!!");
    }

    if ($incidentid > 0) {
    // Insert the first SLA update, this indicates the start of an incident
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'slamet', '{$now}', '{$sit[2]}', '1', 'show', 'opened','The incident is open and awaiting action.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     // Insert the first Review update, this indicates the review period of an incident has started
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reviewmet', '{$now}', '{$sit[2]}', '1', 'hide', 'opened','')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_CREATED', array('incidentid' => $incidentid, 'sendemail' => $send_email));
     debug_log("Succesfully created incident: ".$incidentid);

     //Insert the initial response as we see the first email as the initial response
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('$incidentid', '{$sit[2]}', 'slamet', '$now', '{$owner}', '1', 'show', 'initialresponse','The Initial Response has been made by the automated tracker email.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

    }
    //In case we have no incident ID it means the function failed - FALSE returned by function
    if ($incidentid == FALSE) {
     debug_log("Incident auto create failed: ".$subject);
     return;
    }
    $send_email = 1;
    $owner = suggest_reassign_userid($incidentid, $exceptuserid = 0);

    if ($owner > 0) {
    //Update owner in incidents
     $sql = "UPDATE `sit_incidents` SET owner='$owner', lastupdated='$now' WHERE id='$incidentid'";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_ASSIGNED', array('userid' => $owner, 'incidentid' => $incidentid));

     // add update
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, nextaction) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reassigning', '{$now}', '{$owner}', '1', '{$nextaction}')";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     debug_log("Incident re-assigned to: ".$owner);
    }

    return $incidentid;


   case 1:
    debug_log("Case type Hardware : ");

    $product = 9;//Hardware product
    //Try to recover the product name that is in the email subject (only if the sender followed the rules!)
    $recovprod = trim(recup_prodName($subject, '[',']'));
    if ($recovprod) {
     $prodword = $recovprod;
    }
    //nothing found (no [] brackets or empty) so we set a sentence that matches the same as in our DB
    elseif (!$recovprod) {
     //$prodword = 'HARDWARE NOT AUTOMATICALLY RECOGNISED';
      $prodword = software_name(find_tags_in_subject($subject));
       if (!$prodword){
        $GLOBALS['plugin_reason'] = 'Hardware [Skill-Product not set]';
        return;
       }
    }

    $sql = "SELECT LOWER(id) FROM `sit_software` WHERE name LIKE '%$prodword%' ";
    $result = mysql_query($sql);
    if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);

    $numresults = mysql_num_rows($result);
    $row = mysql_fetch_row($result);
    //No match found
    if ($numresults == 0) {
    //Set the software to a precreated value in the DB
     //$software = 53;
     $GLOBALS['plugin_reason'] = 'Hardware [Skill-Product not set]';
        return;
    }
    //Multiple matches found, take the first one
    //TODO: Improve this as it is not that accurate
    if ($numresults > 0) {
     $software = $row[0];
    }

    $servicelevel = $CONFIG['default_service_level'];
    $siteid = contact_siteid($contactid);

    //Find the hardware contract for this contact
    $sql = "SELECT id FROM `sit_maintenance` WHERE site='{$siteid}' AND product='{$product}' ";
    $result = mysql_query($sql);
    if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);
    $row = mysql_fetch_row($result);
    $contrid = $row[0];
                /*TODO: This was not working correctly, but can be fixed i am sure...
                 * The idea is to change the priority according to the product or skill
                 * if ($software == 4||5||6||7||8||13||17||18||37||45||48)
                {
                    $priority = 3;
                }
                else
                {
                  $priority = 2;
                }
                echo "CONTRACT";
                echo $contrid;
                echo "<br>";
				//Allthese are created as priority medium - Need still to fix the above
				//$priority = 2;*/

    $incidentid='';
    $incidentid = create_incident($origsubject, $contactid, $servicelevel, $contrid, $product,
        $software, $priority = 2, $owner = 0, $status = 1,
        $productversion = '', $productservicepacks = '',
        $opened = '', $lastupdated = '');

    debug_log("Incident ID created : ".$incidentid);
    debug_log("CC address(es) found : ".$ccemail);

    //If we have some cc addresses, then we can update them into the case
    if ($ccemail) {
     $sql = "UPDATE `{$dbIncidents}` ";
     $sql .= "SET ccemail='$ccemail', lastupdated='$now' WHERE id='$incidentid'";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     if (!$result) debug_log("Update to the incident cc email succesfull!!");
    }

    // If we have an incident id - we can now do the updates
    if ($incidentid > 0) {
    // Insert the first SLA update, this indicates the start of an incident
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'slamet', '{$now}', '{$sit[2]}', '1', 'show', 'opened','The incident is open and awaiting action.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     // Insert the first Review update, this indicates the review period of an incident has started
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reviewmet', '{$now}', '{$sit[2]}', '1', 'hide', 'opened','')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_CREATED', array('incidentid' => $incidentid, 'sendemail' => $send_email));
     debug_log("Succesfully created incident: ".$incidentid);

     //Insert the initial response as we see the first email as the initial response
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('$incidentid', '{$sit[2]}', 'slamet', '$now', '{$owner}', '1', 'show', 'initialresponse','The Initial Response has been made by the automated tracker email.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

    }
    //In case we have no incident ID it means the function failed - FALSE returned by function
    if ($incidentid == FALSE) {
     debug_log("Incident auto create failed: ".$subject);
     return;
    }
    $send_email = 1;
    $owner = suggest_reassign_userid($incidentid, $exceptuserid = 0);
    //update the Db with the suggested user to reassign to
    if ($owner > 0) {
     $sql = "UPDATE `sit_incidents` SET owner='$owner', lastupdated='$now' WHERE id='$incidentid'";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_ASSIGNED', array('userid' => $owner, 'incidentid' => $incidentid));

     // add update
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, nextaction) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reassigning', '{$now}', '{$owner}', '1', '{$nextaction}')";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     debug_log("Incident re-assigned to: ".$owner);
    }

    return $incidentid;


   case 2:
    debug_log("Case type Software : ");

    $product = 10;//Software product
    //Try to recover the product name that is in the email subject (only if the sender followed the rules!)
    $recovprod = trim(recup_prodName($subject, '[',']'));
    if ($recovprod) {
     $prodword = $recovprod;
    }
    //nothing found (no [] brackets or empty) so we set a sentence that matches the same as in our DB
    elseif (!$recovprod) {
     //$prodword = 'SOFTWARE NOT AUTOMATICALLY RECOGNISED';
      $prodword = software_name(find_tags_in_subject($subject));
       if (!$prodword){
        $GLOBALS['plugin_reason'] = 'Software [Skill-Product not set]';
        return;
       }
    }

    $sql = "SELECT LOWER(id) FROM `sit_software` WHERE name LIKE '%$prodword%' ";
    $result = mysql_query($sql);
    if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);

    $numresults = mysql_num_rows($result);
    $row = mysql_fetch_row($result);
    //No match found
    if ($numresults == 0) {
     //$software = 54;
     $GLOBALS['plugin_reason'] = 'Software [Skill-Product not set]';
        return;

    }
    //Multiple matches found, take the first one
    //TODO: Improve this as it is not that accurate
    if ($numresults > 0) {
     $software = $row[0];
    }

    $servicelevel = $CONFIG['default_service_level'];
    $siteid = contact_siteid($contactid);
    //Find the software contract for this contact
    $sql = "SELECT id FROM `sit_maintenance` WHERE site='{$siteid}' AND product='{$product}' ";
    $result = mysql_query($sql);
    if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);
    $row = mysql_fetch_row($result);
    $contrid = $row[0];

                /*TODO: This was not working correctly, but can be fixed i am sure...
                 * The idea is to change the priority according to the product or skill
                echo $contrid;
                echo "<br>";
                if ($software == 54||36||38)
                {
                    $priority = 1;
                }
                else
                {
                  $priority = 2;
                }
				//Allthese are created as priority medium - Need still to fix the above
				//$priority = 2;*/
    $incidentid='';
    $incidentid = create_incident($origsubject, $contactid, $servicelevel, $contrid, $product,
        $software, $priority = 2, $owner = 0, $status = 1,
        $productversion = '', $productservicepacks = '',
        $opened = '', $lastupdated = '');

    debug_log("Incident ID created : ".$incidentid);
    debug_log("CC address(es) found : ".$ccemail);

    //If we have some cc addresses, then we can update them into the case
    if ($ccemail) {
     $sql = "UPDATE `{$dbIncidents}` ";
     $sql .= "SET ccemail='$ccemail', lastupdated='$now' WHERE id='$incidentid'";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     if (!$result) debug_log("Update to the incident cc email succesfull!!");
    }

    if ($incidentid > 0) {
    // Insert the first SLA update, this indicates the start of an incident
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'slamet', '{$now}', '{$sit[2]}', '1', 'show', 'opened','The incident is open and awaiting action.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     // Insert the first Review update, this indicates the review period of an incident has started
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reviewmet', '{$now}', '{$sit[2]}', '1', 'hide', 'opened','')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_CREATED', array('incidentid' => $incidentid, 'sendemail' => $send_email));
     debug_log("Succesfully created incident: ".$incidentid);

     //Insert the initial response as we see the first email as the initial response
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, customervisibility, sla, bodytext) ";
     $sql .= "VALUES ('$incidentid', '{$sit[2]}', 'slamet', '$now', '{$owner}', '1', 'show', 'initialresponse','The Initial Response has been made by the automated tracker email.')";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

    }
    //In case we have no incident ID it means the function failed - FALSE returned by function
    if ($incidentid == FALSE) {
     debug_log("Incident auto create failed: ".$subject);
     return;
    }
    $send_email = 1;
    $owner = suggest_reassign_userid($incidentid, $exceptuserid = 0);

    //Update the Db with the suggested owner
    if ($owner > 0) {
     $sql = "UPDATE `sit_incidents` SET owner='$owner', lastupdated='$now' WHERE id='$incidentid'";
     mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);

     trigger('TRIGGER_INCIDENT_ASSIGNED', array('userid' => $owner, 'incidentid' => $incidentid));

     // add update
     $sql  = "INSERT INTO `sit_updates` (incidentid, userid, type, timestamp, currentowner, currentstatus, nextaction) ";
     $sql .= "VALUES ('{$incidentid}', '{$sit[2]}', 'reassigning', '{$now}', '{$owner}', '1', '{$nextaction}')";
     $result = mysql_query($sql);
     if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_ERROR);
     debug_log("Incident re-assigned to: ".$owner);
    }

    return $incidentid;

   case 3:

    $GLOBALS['plugin_reason'] = 'INCORRECTLY formatted';
    debug_log("There was no reference found for the correct skill in: ".$subject);
    return;
  }

 }
}

plugin_register('trigger_variables', 'auto_create_trigger_variables');


function auto_create_trigger_variables() {
 $ttvararray['{emailsubject}'] =
     array('description' => '$Subject of incoming email',
     'requires' => 'subject');


}

plugin_register('trigger_types', 'auto_create_triggers');


function auto_create_triggers() {
 global $triggerarray;

 $triggerarray['TRIGGER_POSSIBLE_DUPLICATE'] =
     array('name' => 'Possible duplicate email',
     'description' => 'Occurs when the auto create function finds possible duplicates',
     'required' => array('incidentid', 'userid'),
     'params' => array('incidentid', 'userid', 'emailsubject')
 );

 $triggerarray['TRIGGER_EMAIL_DUPLICATE_IMPORTED'] =
     array('name' => 'Duplicate email imported into case',
     'description' => 'Occurs when the auto create function has imported a duplicate into the existing case',
     'required' => array('incidentid'),
     'params' => array('incidentid')
 );

}

?>
