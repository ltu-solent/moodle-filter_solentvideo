<?php
// SOLENT VIDEO FILTER TO CATER FOR STREAMING VIDEOS FROM FLASH SERVERS
// CREATED 2012 LTU DARAN PRICE

defined('MOODLE_INTERNAL') || die();


////Function to help check private ip range

function netMatch($network, $ip)
{
    $network = trim($network);
    $ip = trim($ip);
    $d = strpos($network, '-');
   
    if (preg_match("/^\*$/", $network))
    {
        $network = str_replace('*', '^.+', $network);
    }
    if (!preg_match("/\^\.\+|\.\*/", $network))
    {
        if ($d === false)
        {
            $ip_arr = explode('/', $network);
 
            if (!preg_match("/@\d*\.\d*\.\d*\.\d*@/", $ip_arr[0], $matches))
            {
                $ip_arr[0] .= '.0';    // Alternate form 194.1.4/24
            }

            $network_long = ip2long($ip_arr[0]);
            $x = ip2long($ip_arr[1]);
            $mask = long2ip($x) == $ip_arr[1] ? $x : (0xffffffff << (32 - $ip_arr[1]));
            $ip_long = ip2long($ip);
 
            return ($ip_long & $mask) == ($network_long & $mask);
        }
        else
        {
            $from = ip2long(trim(substr($network, 0, $d)));
            $to = ip2long(trim(substr($network, $d+1)));
            $ip = ip2long($ip);
       
            return ($ip >= $from and $ip <= $to);
        }
    }
    else
    {
        return preg_match("/$network/", $ip);
    }
}

//GeoIP Location
include($CFG->libdir.'/geoipVid/geoip.inc');


///////////////////////////////////////////////////////////////////////////////////////////

require_once($CFG->libdir.'/filelib.php');



if (!defined('FILTER_SOLENTVIDEO_VIDEO_WIDTH')) {
    /**
     * Default media width, some plugins may use automatic sizes or accept resize parameters.
     * This can be defined in config.php.
     */
   /* define('FILTER_SOLENTVIDEO_VIDEO_WIDTH', 550);*/
	define('FILTER_SOLENTVIDEO_VIDEO_WIDTH', 455);
}

if (!defined('FILTER_SOLENTVIDEO_VIDEO_HEIGHT')) {
    /**
     * Default video height, plugins that know aspect ration
     * should calculate it themselves using the FILTER_MEDIAPLUGIN_VIDEO_HEIGHT
     * This can be defined in config.php.
     */
    /*define('FILTER_SOLENTVIDEO_VIDEO_HEIGHT', 339);*/
	define('FILTER_SOLENTVIDEO_VIDEO_HEIGHT', 278);
}

//////////////////////////////////////////////////////////////////////////////////////////



//////////////////COMMENTS KEPT FROM ORIGINAL CORE MULTIMEDIA FILTER////////////////////////////////////////////////////
//TODO: we should use /u modifier in regex, unfortunately it may not work properly on some misconfigured servers, see lib/filter/urltolink/filter.php ...
//TODO: we should migrate to proper config_plugin settings ...
/**
 * Automatic media embedding filter class.
 *
 * It is highly recommended to configure servers to be compatible with our slasharguments,
 * otherwise the "?d=600x400" may not work.
 *
 */
 
 
class filter_solentvideo extends moodle_text_filter {

    function filter($text, array $options = array()) {
        global $CFG;
		global $USER;

        if (!is_string($text) or empty($text)) {
            // non string data can not be filtered anyway
            return $text;
        }
        if (stripos($text, '</a>') === false) {
            // performance shortcut - all regexes bellow end with the </a> tag,
            // if not present nothing can match
            return $text;
        }

        $newtext = $text; // we need to return the original value if regex fails!


        // Flash stuff


        if (!empty($CFG->filter_solentvideo_enable_solentmp4)) {
            $search = '/<a\s[^>]*href="([^"#\?]+\.(flv|f4v|mp4)([#\?][^"]*)?)"[^>]*>([^>]*)<\/a>/is';
			if (!empty($CFG->filter_solentvideo_enable_flowplayer)){
            $newtext = preg_replace_callback($search, 'filter_solentvideofp_callback', $newtext);
			}elseif (!empty($CFG->filter_solentvideo_enable_jwplayer)){
            $newtext = preg_replace_callback($search, 'filter_solentvideojw_callback', $newtext);
			}			
        }


        if (empty($newtext) or $newtext === $text) {
            // error or not filtered
            unset($newtext);
            return $text;
        }


        return $newtext;
    }
}


///===========================
/// utility functions

/**
 * Get mimetype of given url, useful for # alternative urls.
 *
 * @private
 * @param string $url
 * @return string $mimetype
 */
function filter_solentvideo_get_mimetype($url) {
    $matches = null;
    if (preg_match("|^(.*)/[a-z]*file.php(\?file=)?(/[^&\?#]*)|", $url, $matches)) {
        // remove the special moodle file serving hacks so that the *file.php is ignored
        $url = $matches[1].$matches[3];
    } else {
        $url = preg_replace('/[#\?].*$/', '', $url);
    }

    $mimetype = mimeinfo('type', $url);

    return $mimetype;
}

/**
 * Parse list of alternative URLs
 * @param string $url urls separated with '#', size specified as ?d=640x480 or #d=640x480
 * @param int $defaultwidth
 * @param int $defaultheight
 * @return array (urls, width, height)
 */
function filter_solentvideo_parse_alternatives($url, $defaultwidth = 0, $defaultheight = 0) {
    $urls = explode('#', $url);
    $width  = $defaultwidth;
    $height = $defaultheight;
    $returnurls = array();

    foreach ($urls as $url) {
        $matches = null;

        if (preg_match('/^d=([\d]{1,4})x([\d]{1,4})$/i', $url, $matches)) { // #d=640x480
            $width  = $matches[1];
            $height = $matches[2];
            continue;
        }
        if (preg_match('/\?d=([\d]{1,4})x([\d]{1,4})$/i', $url, $matches)) { // old style file.ext?d=640x480
            $width  = $matches[1];
            $height = $matches[2];
            $url = str_replace($matches[0], '', $url);
        }

        $url = str_replace('&amp;', '&', $url);
        $url = clean_param($url, PARAM_URL);
        if (empty($url)) {
            continue;
        }

        $returnurls[] = $url;
    }

    return array($returnurls, $width, $height);
}

/**
 * Should the current tag be ignored in this filter?
 * @param string $tag
 * @return bool
 */
function filter_solentvideo_ignore($tag) {
    if (preg_match('/class="[^"]*nosolentvideo/i', $tag)) {
        return true;
    } else {
        false;
    }
}

////////////////////THE CALLBACK FUNCTION, IF THE FILTER RECOGNIZES AN MP4 OR FLV THIS FUNCTION IS CALLED AND USES JWPLAYER/////////////////


function filter_solentvideojw_callback($link) {
    static $count = 0;

    if (filter_solentvideo_ignore($link[0])) {
        return $link[0];
    }

    $count++;
    $id = 'filter_flv_'.time().'_'.$count; //we need something unique because it might be stored in text cache

    list($urls, $width, $height) = filter_solentvideo_parse_alternatives($link[1], 0, 0);
		

    $autosize = false;
    if (!$width and !$height) {
        $width    = FILTER_SOLENTVIDEO_VIDEO_WIDTH;
        $height   = FILTER_SOLENTVIDEO_VIDEO_HEIGHT;
	}else{
		$autosize = true;
    }
	
	///////////////////////////////////SOLENT VIDEO FILTER SPECIFIC///////////////////////////////////////
	global $CFG;////test to see if we need all of these global calls later!!!!
	global $USER;////Needed to return information of rejected video resources email initiated by geoIP check
    global $COURSE;////Needed to return information of rejected video resources email initiated by geoIP check
	///////////////////////////////JWPLAYER CHARACTERISTICS////////////////////
	$skin = $CFG->wwwroot.'/filter/solentvideo/beelden.zip';////location of the player skin
	$controlbar =  "bottom";///Place the control bar at the bottom of the video
	$autoplay = "true";////Set to auto play so video can be buffered and the paused
	$nonUK = $CFG->wwwroot."/filter/solentvideo/pics/3x4_nonUK.jpg";////Outside of the uk image
	$ipadnoshow = $CFG->wwwroot."/filter/solentvideo/pics/3x4_ipad.jpg";
	/////////////////////////////Geoip function///////////////////////////////////////
    $gi = geoip_open($CFG->libdir.'/geoipVid/GeoIP.dat',GEOIP_STANDARD);
    $IPaddress=$_SERVER['REMOTE_ADDR'];//Remote ip
    $countryName = geoip_country_name_by_addr($gi,$IPaddress);//Remote ip to country code and country name
    $countryCode = geoip_country_code_by_addr($gi,$IPaddress);
    if ($countryCode==""){//If not in GeoIP database
	$countryCode="an unkown";
	$countryName="an unkown";
	}  
	
	
	
	//////////////////WHAT IS THE LINK ARRAY?//////////////////////////////////////////////////////////////	
   // echo '<strong>This is the link array</strong><br />';////Test the link array
	//print_r($link);////Print the array
	//echo '<br />';////Add line after link array print test
	
	
	
	///////////////IS THE RESOURCE STREAMING?/////////////////////////////////////////////////////////////
    $isstreaming = $link[1];////Grab the link from the array
	$isstreaming = substr($isstreaming,0,4);////Grab the first four characters of the string
	if ($isstreaming == 'rtmp'){
		$streamingresource = 'Yes';////Set a variable to Yes for later reference
	}else{
		$streamingresource = 'No';////Set a variable to No for later reference
	}
	//echo '<br /><strong> Is the video resource streaming?: </strong>'.$streamingresource.'<br />'; ////Test the if the resource is streaming or not

	
	
	///////////////WHAT STREAMING SERVER IS IT COMING FROM?/////////////////////////////////////////////////////////////////////////
	if ($streamingresource == 'Yes'){////If it is a streaming resource we need to work out which server it is coming from
    $streamer = $link[1];////Grab the link from the array
	$streamer = substr($streamer,7,8);////Strip the link to just the server name
	//echo '<br /> <strong>This is the streaming Server:</strong> '.$streamer.'<br />';////Test the variable for the streamer
	}
	
	
	
	////////////////WHAT FOLDER/APPLICATION IS THE VIDEO LOCATED IN?///////////////////////////////////////////////////////////////
	if ($streamingresource == 'Yes'){////If it is a streaming resource we need to work out which folder or application the video is in
	$streamingfolder = $link[1];////Grab the link from the array
	$streamingfolder = substr($streamingfolder, 29);////Strip away the server address
	$pos = stripos($streamingfolder,'/');
	$streamingfolder = substr($streamingfolder, 0, ($pos));
	//echo '<br /> <strong>This is the streaming folder:</strong> '.$streamingfolder.'<br />';////Test the variable for the streameinf folder
	}
	
	
	
	///////////////WHAT IS THE VIDEO FILE NAME AND FILETYPE?////////////////////////////////////////////////////////////////////////////////////
	$videoname = $link[1];////Grab the link from the array
	$findquery = strripos($videoname,'?');////Are there any variables after the url
	if ($findquery != false){
	$videoname = strstr($videoname, '?', true);////If there are any variables after the url strip them away
	}
	$pos = strripos($videoname,'/');////Find the last occurance of '/' character
	$videoname = substr($videoname, ($pos+1));////Strip the string of everything but the video name
	//$videoname = substr($videoname,0,-4);//Take off .mp4 characters from video name
	$videoname = urldecode($videoname);////Replace any html characters
	//echo '<br /><strong>This is the video name:</strong> '.$videoname.'<br />';////Test the video name var
	//$filetype = substr($videoname,(strlen($videoname)-3));
	$filetype = $link[2];
	//echo '<br /> This is the filetype: '.$filetype.'<br />';////Test the filetype var


	////////////////SHOULD ONLY BE ADDED TO LIBRARY LINKS SECTON AS IT WILL THROUGH AN ALERT OTHERWISE////////////////////////////////////
	if (($link[3] != NULL) ){
	$libvars = ($link[3]);//Grab the variable form the link array
	$libvars = str_replace('&amp;', '&', $libvars);//get rid of ampersand
	$libvars = str_replace('?title', 'title', $libvars);//get rid of ?
	urldecode($libvars);//get rid of html code for spaces etc...
    parse_str($libvars,$liboutput);//Create a new array with the libary title and broadcast info	
	//echo '<br /><strong>This is the library info vars array</strong><br />';////Test the new array with library ERA details
	//print_r ($liboutput);////Echo the array with library ERA details	
	if (isset ($liboutput['title'])){
	$eratitle = ($liboutput['title']);////Set php variable with title populated from the array
    //echo '<br /><br /><strong>This is the library info title: '.$eratitle.'</strong><br />';////Test the variable that is populated by the video resource title
	$broadcast = ($liboutput['broadcast']);
	//echo '<br /><strong>This is the library info broadcast: '.$broadcast.'</strong><br />';
	}
	}
	
	
	
	
	/////////////////////RETURN THE VIDEO CONTENT////////////////////////////////////////////////////////


	
	////////////////////IT IS AN FLV OR MP4 FROM THE MOODLE DIRECTORY////////////////////////////////////	
	if ($streamingresource == 'No'){
		echo '<br /><strong>This is a video in the moodle directory.</strong><br /><br />';////Test that it is coming from moodle directory
		if (($filetype != 'flv') && (strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )){////ITS AN MP4 SO PLAY IT ON IPAD WITH VIDEO TAG
					$mp4_url = $link[1];
					/*NO LONGER USING MOBILE THEME?
					if (strpos(current_theme(),"mobile") !==false){
					 $width ='300' ;
					 $height  = '183';
					}
					*/
                    $size = 'width="'.$width.'" height="'.$height.'"';
					$output = '<br/> <video controls = "true" '.$size.' src="'.$mp4_url.'" ></video><br>';
					
				}elseif (($filetype == 'flv') && (strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )){////ITS AN FLV AND CANT BE SHOWN ON AN IPAD SHOW A RESOURCE UNAVAILABLE IMAGE
				$output = '<div id="'.$id.'"><img src="'.$ipadnoshow.'" alt="Video Resource not available on this device" width= "'.$width.'" hieght="'.$height.'"  /> </div>';
				
				}else{//////ITS NOT AN IPAD SO PLAY IT WITH JWPLAYER

	   
	   $output = ' <style>
				  #'.$id.'_wrapper{background-image:url('.$CFG->wwwroot.'/filter/solentvideo/pics/previewsq.jpg); background-repeat: no-repeat;background-position:center; background-color: black;  max-width:'.$width.'px; width:100%; height:auto;}
				  </style>
                  <div id="'.$id.'">Loading the player ...</div><br/>
				  
                  <script type="text/javascript">
                  jwplayer("'.$id.'").setup({
                  flashplayer: "'.$CFG->wwwroot.'/filter/solentvideo/player_new.swf",
                  file: "'.$link[1].'",
                  width: "100%",
				  aspectratio: "16:9",				  
                  autoplay: '.$autoplay.',
                  controlbar: "'.$controlbar.'",
                  skin: "'.$skin.'" });
				  </script>
				  
                  <script type="text/javascript">
                  var element = document.getElementById("'.$id.'");
                  jwplayer(element).pause();
                  </script>';////Return the output code to embed in page
					  
				 /* $mp4_url = $link[1];
					if (strpos(current_theme(),"mobile") !==false){
					 $width ='300' ;
					 $height  = '183';
					}
                    $size = 'width="'.$width.'" height="'.$height.'"';
					$output = '<br/> <video controls = "true" '.$size.'  src="'.$mp4_url.'" ></video><br/><br/>';*/
			
	}
	}
     /////////////////////////END MOODLE DIRECTORY CODE//////////////////////////////////


	
	
	
	////////////////////IT IS A STREAMING VIDEO RESOURCE////////////////////////////////////////////////////
	if ($streamingresource == 'Yes'){
		//echo $_SERVER['REQUEST_URI'];
		//echo '<br /><strong>This is coming from a streaming server</strong><br />';///Test that it is coming from streaming server			
		if ($streamer == 'vlemedia' || $streamer == 'estreamp'){
             $mp4_url = $link[1];			 
			 if (strpos($mp4_url, "liveevent") !== false){
				 
				 			 if (($filetype != 'flv') && (strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || (strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )&& (strpos($_SERVER['HTTP_USER_AGENT'],"Mozilla") !==true))){								
			  $mp4_url = str_replace('rtmp','http', $mp4_url);
			  $mp4_url = str_replace('mp4','m3u8', $mp4_url);
			  //echo $mp4_url.'<br />';
			  $size = 'width="'.$width.'" height="'.$height.'"';
			  //echo $size;
			 $output =  ' <video controls src="http://vlemedia.solent.ac.uk/hls-live/livepkgr/_definst_/liveevent/livestream.m3u8" width = "640" height = "360">
    Your browser does not support the VIDEO tag and/or RTMP streams.
</video><br /><br /><a href="http://vlemedia.solent.ac.uk/hls-live/livepkgr/_definst_/liveevent/livestream.m3u8">Live stream</a>';
			 }else{
			  $mp4_url = str_replace('rtmp','http', $mp4_url);
			  $mp4_url = str_replace('mp4','f4m', $mp4_url);
			// echo $mp4_url.'<br />';
				 $output = ' 
				 <object width="600" height="409"> <param name="movie" value="'.$CFG->wwwroot.'/filter/solentvideo/SampleMediaPlayback.swf"></param><param name="flashvars" value="src=http://vlemedia.solent.ac.uk/hds-live/livepkgr/_definst_/liveevent/livestream.f4m"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="'.$CFG->wwwroot.'/filter/solentvideo/SampleMediaPlayback.swf" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="600" height="409" flashvars="src=http://vlemedia.solent.ac.uk/hds-live/livepkgr/_definst_/liveevent/livestream.f4m&$streamType=live&bufferingOverlay=false"></embed></object>';////Return the output code to embed in page
			 }
			 
			 } else if ((strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )&& (strpos($_SERVER['HTTP_USER_AGENT'],"like&nbsp;Gecko") !==true) && (strpos($_SERVER['HTTP_USER_AGENT'],"Firefox") !==false)){
				 				$output = '<div id="'.$id.'"><img src="'.$ipadnoshow.'" alt="Video Resource not available on this device" width= "'.$width.'" hieght="'.$height.'"  /> </div>';
			 }

				elseif (($filetype != 'flv') && (strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || (strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )&& (strpos($_SERVER['HTTP_USER_AGENT'],"Mozilla") !==true)) ||  (strpos($_SERVER['REQUEST_URI'],"/mod/book/tool/exportepub/index.php") !== false)){////ITS AN MP4
if (strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false){
	//echo 'IT IS AN IPAD!!!!<br />';
}
							
					$mp4_url = $link[1];
					if (strpos($_SERVER['REQUEST_URI'],"/mod/book/tool/exportepub/index.php") !== false){
					//$mp4_url = str_replace('rtmp://'.$streamer.'.solent.ac.uk/','http://vlemedia.solent.ac.uk/hls-',$mp4_url.'.m3u8');
					$mp4_url = str_replace('rtmp://'.$streamer.'.solent.ac.uk/','http://vlemedia.solent.ac.uk/epub-',$mp4_url);
					}else{
					$mp4_url = str_replace('rtmp://'.$streamer.'.solent.ac.uk/','http://vlemedia.solent.ac.uk/hls-',$mp4_url.'.m3u8');	
					}

				

					$height = '256';
					if (strpos(current_theme(),"mobile") !==false){
					 $width ='300px' ;
					 $height  = '183px';
					}
					if (strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false ||  (strpos($_SERVER['REQUEST_URI'],"/mod/book/tool/exportepub/index.php") !== false)){
											 $poster = $CFG->wwwroot.'/filter/solentvideo/pics/16x9.jpg';
				 }else{
					$poster = '';
				 }
                    $size = 'width="'.$width.'" height="'.$height.'"';
					if (strpos($_SERVER['REQUEST_URI'],"/mod/book/tool/exportepub/index.php") !== false){
						$poster = 'images/16x9.jpg';
						$output =  '<video src="'.$mp4_url.'" controls = "controls" '.$size.' poster = "'.$poster.'"></video>';
					}else{
					$output =  '<div class="solent_videoWrapper"><video src="'.$mp4_url.'" controls = "controls" '.$size.' poster = "'.$poster.'"></video></div><br>here';
					}
					
				}elseif (($filetype == 'flv') && (strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false) || (strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false )&& strpos($_SERVER['HTTP_USER_AGENT'],"Firefox") !==false ){////ITS AN FLV AND CANT BE SHOWN ON AN IPAD SO SH0W RESOURCE UNAVAILABLE IMAGE
				
				$output = '<div id="'.$id.'"><img src="'.$ipadnoshow.'" alt="Video Resource not available on this device" width= "'.$width.'" hieght="'.$height.'"  /> </div>';
					
				}else{////ITS NOT AN IPAD SO PLAY IT WITH JWPLAYER
				
                if ($streamingfolder == 'RecordStream'){//IF IT IS A WEBCAM RECORDING CHANGE THE WIDTH
					$width = '352';
					$aspectratio = '4:3';
				}else{
					$width = '460';
					$aspectratio = '16:9';
					$padding = 'padding-bottom: 56.25%; /* 16:9 */';
				}
				/*NO LONGER USING MOBILE THEME?
					if (strpos(current_theme(),"mobile") !==false){
					 $width ='300px' ;
					 $height  = '183px';
				 }
				 */
				  $output = '
				  

				 <style>
				  #'.$id.'_wrapper{background-image:url('.$CFG->wwwroot.'/filter/solentvideo/pics/previewsq.jpg); background-repeat: no-repeat;background-position:center; background-color: black;  max-width:'.$width.'px; width:100%; height:auto;}
				  </style>
                  <div id="'.$id.'"  >Loading the player...</div><br/>
				  				  
                  <script type="text/javascript">
                  jwplayer("'.$id.'").setup({
                  flashplayer: "'.$CFG->wwwroot.'/filter/solentvideo/player_new.swf",
                  file: "'.$videoname.'",
                  streamer: "rtmpe://vlemedia.solent.ac.uk/'.$streamingfolder.'",
                  width: "100%",
				  aspectratio:"'.$aspectratio.'",
                  autoplay: '.$autoplay.',
                  controlbar: "'.$controlbar.'",
                  skin: "'.$skin.'",
				          modes: [
		   { type: "flash", src: "'.$CFG->wwwroot.'/filter/solentvideo/player_new.swf" }
        ]
		
 });
				  </script>
				  
                 <script type="text/javascript">

                 jwplayer("'.$id.'").onBufferFull(function() { 
                     jwplayer("'.$id.'").pause();
					 });
                 </script>';////Return the output code to embed in page
				 

				}////END VLEMEDIA ELSE CODE
		}////END VLEMEDIA CODE		
		
		
		
		
		
		if ($streamer == 'estreamc'){/////It is coming from the library streaming server
			//echo '<br /><strong>This is coming from the library server.</strong><br />';////Test it is coming from the library server
			
							if ((strpos($_SERVER['HTTP_USER_AGENT'],"iPhone") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"iPad") !==false|| strpos($_SERVER['HTTP_USER_AGENT'],"iPod") !==false || strpos($_SERVER['HTTP_USER_AGENT'],"Android") !==false)){////ITS ON AN IPAD WHICH CAN NOT ACCESS LIBRARY RESOURCES OVER HTTP
			$output = ' 
				  <table  border="0" cellpadding="5">
                  <tr>
                  <td><strong>'.$eratitle.'</strong></td>
                  </tr>
                  <tr>
                  <td>Broadcast: '.$broadcast.'</td>
                  </tr>
                  <tr>	 
				  <td>  
                  <div id="'.$id.'"><img src="'.$ipadnoshow.'" alt="Video Resource not available on this device" width= "'.$width.'" hieght="'.$height.'"  /> </div>
				  </td>
                  </tr>
                  <tr>
                  <td>	<br/>This recording is to be used only for educational and non-commercial purposes under the terms of the ERA Licence.
                  </td>
				  </tr>
                  </table>';							
					
					}
					
					elseif (($countryCode=="GB")or(netMatch('10.0.0.0-10.255.255.255', $_SERVER['REMOTE_ADDR']))){////ITS NOT AN IPAD AND IN UK SO PLAY WITH JWPLAYER
				  $output = '
				  <table width="100%" border="0" cellpadding="5">
                  <tr>
                  <td><strong>'.$eratitle.'</strong></td>
                  </tr>
                  <tr>
                  <td>Broadcast: '.$broadcast.'</td>
                  </tr>
                  <tr>	 
				  <td>  
				 <style>
				  #'.$id.'_wrapper{background-image:url('.$CFG->wwwroot.'/filter/solentvideo/pics/previewsq.jpg); background-repeat: no-repeat;background-position:center; background-color: black;  max-width:'.$width.'px; width:100%; height:auto;}
				  </style>
				  </style>
                  <div id="'.$id.'">Loading the player ...</div>
				  </td>
                  </tr>
                  <tr>
                  <td>	<br/>This recording is to be used only for educational and non-commercial purposes under the terms of the ERA Licence.
                  </td>
				  </tr>
                  </table>
				  				  
                  <script type="text/javascript">
                  jwplayer("'.$id.'").setup({
                  flashplayer: "'.$CFG->wwwroot.'/filter/solentvideo/player_new.swf",
                  file: "'.$videoname.'",
                  streamer: "rtmpe://'.$streamer.'.solent.ac.uk/'.$streamingfolder.'",
                  width: "100%",
				  aspectratio: "16:9",
                  autoplay: '.$autoplay.',
                  controlbar: "'.$controlbar.'",
                  skin: "'.$skin.'" });
				  </script>
				  
                 <script type="text/javascript">
                 jwplayer("'.$id.'").onBufferFull(function() { 
                     jwplayer("'.$id.'").pause();});
                 </script>';////Return the output code to embed in page			
		             
					 }else{////////THE USER IS OUTSIDE THE UK AND CAN NOT VIEW THE RESOURCE
					 
			$output = ' 
				  <table width="100%" border="0" cellpadding="5">
                  <tr>
                  <td><strong>'.$eratitle.'</strong></td>
                  </tr>
                  <tr>
                  <td>Broadcast: '.$broadcast.'</td>
                  </tr>
                  <tr>	 
				  <td>  
                  <div id="'.$id.'"><img src="'.$nonUK.'" alt="Video Resource only available in the UK" width= "'.$width.'" hieght="'.$height.'"  /> </div>
				  </td>
                  </tr>
                  <tr>
                  <td>	<br/>As you are located in '.$countryName.' you are not able to view this resource <br/>
				  <br/>This recording is to be used only for educational and non-commercial purposes under the terms of the ERA Licence.
                  </td>
				  </tr>
                  </table>';
				  
				  ////////SEND AN EMAIL ALERT TO MYCOURSE INBOX//////////////////////////////////////////////////////////////////////
				  $Name = " $USER->username"; //senders name
                  $email = "$USER->email"; //senders e-mail adress
                  $recipient = "mycourse@solent.ac.uk"; //recipient
                  $mail_body = "MyCourse rejected a request for a video resource from a user outside of the UK.
                  Video Resource:$eratitle.
                  Name:$USER->firstname $USER->lastname.
                  UserName:$USER->username.
                  Department:$USER->department.
                  Email:$USER->email.
                  Tel:$USER->phone1.
                  Unit:$COURSE->fullname.
                  Unit Code:$COURSE->shortname.
                  IP:$IPaddress.
                  Location:$countryName."; //mail body
                  $subject = "MyCourse rejected non UK connection"; //subject
                  $header = "From: ". $Name . " <" . $email . ">\r\n"; //optional headerfields

                  mail($recipient, $subject, $mail_body, $header); //mail command :) 
		
	 }// END LIBRARY ELSE CODE
	 
  }////END LIBRARY CODE
		
}////END STREAMING CODE

	
	
    return $output;

}



/////////////////////////////////////FLOWPLAYER CURRENTLEY NOT FULLY FUNCTIONAL BUT HAVE LEFT CALLBACK HERE IN CASE WE NEED TO WORK ON IT FOR THE FUTURE/////

function filter_solentvideofp_callback($link) {
    static $count = 0;

    if (filter_solentvideo_ignore($link[0])) {
        return $link[0];
    }

    $count++;
    $id = 'filter_flv_'.time().'_'.$count; //we need something unique because it might be stored in text cache

    list($urls, $width, $height) = filter_solentvideo_parse_alternatives($link[1], 0, 0);
		

    $autosize = false;
    if (!$width and !$height) {
        $width    = FILTER_SOLENTVIDEO_VIDEO_WIDTH;
        $height   = FILTER_SOLENTVIDEO_VIDEO_HEIGHT;
	}else{
		$autosize = true;
    }
		
    echo '<strong>This is the link array</strong><br />';
	print_r($link);////Print the array
	//IS THE RESOURCE STREAMING?
    $isstreaming = $streamer = $link[1];
	$isstreaming = substr($isstreaming,0,4);
	if ($isstreaming == 'rtmp'){
		$streamingresource = true;
	}else{
		$streamingresource = false;
	}
	//WHAT STREAMING SERVER IS IT COMING FROM?
    $streamer = $link[1];
	$streamer = substr($streamer,7,8);	
	
	//WHAT IS THE FILE NAME?
	$videoname = $link[1];
	$findquery = strripos($videoname,'?');
	if ($findquery != false){
	$videoname = strstr($videoname, '?', true);
	}
	$pos = strripos($videoname,'/');
	$videoname = substr($videoname, ($pos+1));
	$videoname = substr($videoname,0,-4);
	echo 'This is the video name: '.$videoname.'<br />';
	

	
	if ($streamingresource == 'true'){
		echo ' This is a streaming video resource!! <br />';
		
		////////////////////////////////////////////////////////////////////////////
		
		echo '
		<!-- setup player normally -->
<a class="player" id="fms">
	<img src="http://static.flowplayer.org/img/player/btn/showme.png"  />
</a>
<script>
$f("fms", "http://releases.flowplayer.org/swf/flowplayer-3.2.7.swf", {

	clip: {
		url: \'metacafe\',
		// configure clip to use influxis as our provider, it uses our rtmp plugin
		provider: \'influxis\'
	},

	// streaming plugins are configured under the plugins node
	plugins: {

		// here is our rtpm plugin configuration
		influxis: {
			url: \'filter/solentvideo/flowplayer.rtmp-3.2.3.swf\',

			// netConnectionUrl defines where the streams are found
			netConnectionUrl: \'rtmp://dk2isqp3f.rtmphost.com/flowplayer\'
		}
	}
});
</script>

		
		';
	}else{
        
		
		


	//SHOULD ONLY BE ADDED TO LIBRARY LINKS SECTON AS IT WILL THROUGH AN ALERT OTHERWISE
	if ($link[3] != NULL){
	$libvars = ($link[3]);//Grab the variable form the link array
	$libvars = str_replace('&amp;', '&', $libvars);//get rid of ampersand
	$libvars = str_replace('?title', 'title', $libvars);//get rid of ?
	urldecode($libvars);//get rid of html code for spaces etc...
    parse_str($libvars,$liboutput);//Create a new array with the libary title and broadcast info	
	//echo '<br /><strong>This is the library info vars array</strong><br />';
	//print_r ($liboutput);	
	$eratitle = ($liboutput['title']);
   // echo '<br /><br /><strong>This is the library info title: '.$eratitle.'</strong><br />';
	$broadcast = ($liboutput['broadcast']);
	//echo '<br /><strong>This is the library info broadcast: '.$broadcast.'</strong><br />';
	}

    $flashurl = null;
    $sources  = array();
	
    foreach ($urls as $url) {
        $mimetype = filter_solentvideo_get_mimetype($url);
        if (strpos($mimetype, 'video/') !== 0) {
            continue;
        }
        $source = html_writer::tag('source', '', array('src' => $url, 'type' => $mimetype));
        if ($mimetype === 'video/mp4') {
            // better add m4v as first source, it might be a bit more compatible with problematic browsers
            array_unshift($sources, $source);
        } else {
            $sources[] = $source;
        }

        if ($flashurl === null) {
            $flashurl  = $url;
        }
    }
    if (!$sources) {
        return $link[0];
    }
	

    $info = trim($link[4]);
    if (empty($info) or strpos($info, 'http') === 0) {
        $info = get_string('fallbackvideo', 'filter_solentvideo');
    }
    $printlink = html_writer::link($flashurl.'#', $info, array('class'=>'mediafallbacklink')); // the '#' prevents the QT filter

    $title = s($info);
	

    if (count($sources) > 1) {
        $sources = implode("\n", $sources);

        // html 5 fallback
        $printlink = <<<OET
<video controls="true" width="$width" height="$height" preload="metadata" title="$title">
$sources
$printlink
</video>
<noscript><br />
$printlink
</noscript>
OET;
    }

    // note: no need to print "this is flv link" because it is printed automatically if JS or Flash not available

    $output = html_writer::tag('span', $printlink, array('id'=>$id, 'class'=>'solentvideo solentvideo_flv'));
    $output .= html_writer::script(js_writer::function_call('M.util.add_video_player', array($id, rawurlencode($flashurl), $width, $height, $autosize,$plugin))); // we can not use standard JS init because this may be cached

    return $output;
}
}

