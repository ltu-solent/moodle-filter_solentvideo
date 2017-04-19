<?php
////Function to help check private ip range

function netMatch($network, $ip){
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
?>