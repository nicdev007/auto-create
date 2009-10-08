<?php
/* function_autocreate.php - Functions used specifically for the plugin
 * Auto_create
 * Author:Nicolaas du Toit
 * 
 * 
 */ 

// Function to count how many times keywords appear
function substr_count_array( $haystack, $needle ) {
 $count = 0;
 foreach ($needle as $substring) {
  $count += substr_count( $haystack, $substring);
 }
 return $count;
}

/**
 * search in the Incidents DB for incident(s) titles that match the subject of this email
 * @author Nico du toit
 * @param string $subject. Normally the subject of an email to compare as from the inbound script
 * @param string $contactid. The contact id to check the incidents against (saves time .. i think)
 * @return Returns either YES(continue with auto create), NO (don't auto create) or the incidentID
 *          (of an exact match between the title and the email subject)
 */
function check_for_duplicates($subject, $contactid) {
//Revert to second function that does the preg_match
 $count_dup = find_duplicate_cases($subject, $contactid);
 //There is one match for the subject in the DB, array is returned
 if(is_array($count_dup)) {
 // TODO: **Depreciated - after verification we can remove this
 //$incidentid = id_of_duplicate($subject, $contactid);
  $count = $count_dup[0];
  $incidentid = $count_dup[1];
  debug_log("Duplicate email sent!! - Reverting with incidentid to import into case !!".$incidentid);
  //Just in case the search fails and no ID is retrieved we need to exit correctly
  if (!$incidentid) {
   debug_log("Incident ID could not be found as intended !!");
   $create = "NO";
   return $create;
  }
  trigger('TRIGGER_EMAIL_DUPLICATE_IMPORTED', array('incidentid' => $incidentid));
  $create = $incidentid;
  return $create;
 }


 //There are multiple matches for the subject in the DB

 if(!is_array($count_dup) && $count_dup > 1) {
  $id = 0;
  $uid = 3;//TODO: For now this is my id but i need to change it to system's later
  debug_log("Possible duplicate email. Number of cases with similar subject: ".$count_dup." - NOT auto creating!! /n ");
  trigger('TRIGGER_POSSIBLE_DUPLICATE', array('incidentid' => $id, 'userid' => $uid, 'emailsubject' => $subject));
  $create = "NO";
  return $create;
 }

 //There are no results thus return and auto create the case if possible
 if(!is_array($count_dup)&& $count_dup == 0) {
  debug_log("No duplicates found continuiing !!".$count_dup);
  $create = "YES";
  return $create;
 }
}


/**
 * search in the incidents db for an incident(s) title that matches the subject
 * @author Nico du toit
 * @param string $subject. Normally the subject of an email to compare
 * @param string $contacid. The contactid from the incoming email script
 * @return value of the number of times the title occurs in the string OR
 *         an array with the incidentid of an exact match.
 */

function find_duplicate_cases($subject, $contactid) {
 global $dbIncidents;

 $lower_subject = strtolower($subject);
 $sql = "SELECT title, id FROM `sit_incidents` WHERE contact = '{$contactid}' ";
 $result = mysql_query($sql);
 if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);

 $count=0;
 $nmbresult= mysql_num_rows($result);
 debug_log("Number of results that match:".$nmbresult);
 $i = 0;
 while ($row=mysql_fetch_object($result)) {

  $title_test[]=$row->title;
  $title_incidentid[]=$row->id;

  $our_title = $title_test[$i];
  $match_incidentid = $title_incidentid[$i];
  if(preg_match("/".preg_quote($our_title)."/i",$lower_subject)) {
   debug_log("Row title:  ".$our_title);
   debug_log("Matching id:  ".$match_incidentid);
   $pass_incidentid = $match_incidentid;
   $count = $count + 1;
  }
  $i++;
 }

 debug_log("The count of duplicates was : ".$count);

 //If there is only one result, create an array with the incident id to pass back
 if ($count == 1) {
  debug_log("Single title incident ID : ".$pass_incidentid);
  $count_array = array($count, $pass_incidentid);
  print_r($count_array);
  return $count_array;
 }
 //more than one result pass back count
 return $count;
}

/** DEPRECIATED - I have included this in another function
 * Find the incident id for the matched title
 * @author Nico du toit
 * @param string $subject. Normally the subject of an email to compare
 * @return incident id of the incident
 */

function id_of_duplicate($subject, $contactid) {
 global $dbIncidents;
 //TODO: Need to improve this function .. bad results possible .. not specific enough
 $lower_subject = strtolower($subject);
 $sql = "SELECT LOWER(id) FROM `{$dbIncidents}` WHERE contact='{$contactid}' AND title LIKE '%$lower_subject' ";
 $result = mysql_query($sql);
 if (mysql_error()) trigger_error("MySQL Query Error ".mysql_error(), E_USER_WARNING);
 $oneresult = mysql_fetch_row($result);
 $dupincidentid = $oneresult[0];
 return $dupincidentid;
}

function mysql_fetch_all($result) {
 while($row=mysql_fetch_array($result)) {
  $return[] = $row;
 }
 return $return;
}

/**
 * Find the product name between two [] in the string
 * @author Mattias Rouyre
 * @param string $chaine. The string to be checked
 * @return The text between the 2 brackets or FALSE if it does not exist
 */

function recup_prodName($chaine, $debut,$fin) {
 $pos1 = strpos($chaine, $debut);

 if ($pos1 === false) {
  return false;
 } else {
  $pos1=$pos1+1;
  $pos2 = strpos($chaine, $fin, $pos1);
  if ($pos2 === false) {
   return false;
  } else {

   $pos2 = $pos2-$pos1+strlen($fin);
   $pos2=$pos2-1;
   $chaine=$chaine;
   $selection = substr($chaine, $pos1, $pos2);
   //$selection = "<u>".$selection."</u>";
   return $selection;
  }
 }
}

/**
 * Extract the cc adresses from the incoming email.
 * and break it into a usable string to update into the case
 * @author Nicolaas du Toit
 * @param string $decoded. All the decoded addresses from the Mime class
 * @return a string containing the email addresses only seperated by ", "
 */

function find_cc_decoded($decoded) {
 if (is_array($decoded[0]['ExtractedAddresses']['cc:'])) {
  $cur = 1;
  foreach ($decoded[0]['ExtractedAddresses']['cc:'] as $var) {
   $num = count($decoded[0]['ExtractedAddresses']['cc:']);
   echo "<br> Number of CC's  ".$num;

   $cc .= $var['address'];
   if ($cur != $num) $cc .= ", ";

   $cur++;
  }
 }
 return $cc;
}

?>
