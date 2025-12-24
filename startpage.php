<?php
// Since I had to do a bunch of updates I re-wrote the comments to better explain wtf is going on.
// Anyways, this section is where the web server grabs all of the important stuff like the IP, and any additional clues in case proxies are being used, etc etc....

$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$remote_addr = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '');
$remote_port = htmlspecialchars($_SERVER['REMOTE_PORT'] ?? '');
$remote_user = htmlspecialchars($_SERVER['REMOTE_USER'] ?? '');
$redirect_remote_user = htmlspecialchars($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
$http_user_agent = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '');

// We do a reverse DNS lookup on the IP by grabbing the PTR record via gethostbyaddr as well as drop a generic 'woops' message if it's not found.;
$remote_host = gethostbyaddr($remote_addr);
$remote_host = $remote_host !== $remote_addr ? htmlspecialchars($remote_host) : '<b>[notice]</b> NO REVERSE DNS/PTR RECORD FOUND';

// Setting up a place for all of the things that weren't matched in my frankenstein database of ASNs and Geo info...
$missing_info = [];

// Lets convert the IP to long (long integer) so that we can do comparisons easier in the SQLite database..
function ip_to_long($ip) {
    return sprintf('%u', ip2long($ip));
}

// This is where the compare is done on the almost 200MB SQLite file (which used to be multiple GB). Still, it needs to be shrunk and made more efficent but I
// havent had the time to finish the changes yet. 

function get_geo_location($ip, $db_file) {
    $geo_data = [];
    $ip_long = ip_to_long($ip);

    $db = new SQLite3($db_file);
    $stmt = $db->prepare('SELECT * FROM ip_ranges WHERE ? BETWEEN start_ip AND end_ip LIMIT 1');
    $stmt->bindValue(1, $ip_long, SQLITE3_INTEGER);

    $result = $stmt->execute();
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $geo_data['Country_Code'] = $row['country_code'];
        $geo_data['Country_Name'] = $row['country_name'];
        $geo_data['Continent_Code'] = $row['continent_code'];
        $geo_data['Continent_Name'] = $row['continent_name'];
        $geo_data['ASN'] = $row['asn'];
        $geo_data['AS_Name'] = $row['as_name'];
        $geo_data['AS_Domain'] = $row['as_domain'];
    }

    $db->close();
    return $geo_data;
}

// Here's where we show the PHP script where our Oracle of Truth is, and the answer is: It's outside of the wwwroot ;)
// For a little more detail: The reason is simple: This file is pretty large, and since I have a lot of data in here that some assholes
// sell for money, it's likely something that people would hotlink to without credit and rape my server's bandwidth. I share my files via other places that can afford the bandwidth rape.

$db_file = '/var/www/database/country_asn.db';
$geo_data = get_geo_location($remote_addr, $db_file);

// Continuining on.. Here's where we match the Geo info from the SQLite DB to the ASN that owns the IP address..
$country_name = $geo_data['Country_Name'] ?? '';
$continent_name = $geo_data['Continent_Name'] ?? '';
$location = "$country_name, $continent_name";

$asn = $geo_data['ASN'] ?? '';
$as_name = $geo_data['AS_Name'] ?? '';
$as_domain = $geo_data['AS_Domain'] ?? '';

/* * *
Here's a cool little trick I use to stuff the <title> with the IP address for the BARE MINIMUM, smallest wink of info.
It's a good way to let people script based off of the data that I'm providing, doesn't have a ton of extra crap, and
also allows us to show up in search engines with their scraper bot IPs ;)
Here's the old version prior to Jan 1 2026...
echo "<title>IP: $client_ip - IP.URLS.IS Startpage with Tor Check by PACKET.TEL LLC</title><pre>\r\n";
And below is the current, 2026 version...
* * */
echo "<title>IP: $client_ip is your IP. Provided by PACKET.TEL IP Echo Service at ip.urls.is</title><pre>\r\n";
/* * *
Outputting the text to the rest of the <body>. I should probably include </head><body> but im lazy.
Sidenote: This is the latest addition to ip.urls.is in that /startpage lets you set your Browser's Startpage to 
https://ip.urls.is/startpage so that you can see what your external IP is before you do anything. It tells
you whether you're routing through Tor or not based on torproject.org exit lists, pretty much exactly the same way
https://check.torproject.org does, except I know ip.urls.is isnt being used for evil......
* * */

echo "[ Detected Browser Information ] \r\n";
// Display Client_IP value
if (!empty($client_ip)) {
    echo "CLIENT_IP Address: $client_ip \r\n";
}

// Display the REMOTE_ADDR Field (When Available)
if (!empty($remote_addr)) {
    echo "REMOTE_ADDR Address: $remote_addr \r\n";
} else {
// Setup and start adding stuff to the missing_info var that will display at the very end of the script run. 
    $missing_info[] = 'Your IP Address';
}

// Display Remote IP's Reverse DNS (AKA PTR Record) if Available:
if (!empty($remote_host) && $remote_host !== 'NO REVERSE DNS/PTR RECORD FOUND') {
    echo "Reverse DNS Hostname (PTR Record): $remote_host \r\n";
} else {
    $missing_info[] = 'Hostname (PTR Record)';
}

// Display Remote Machine's Source Port Used by the Client/Browser To Request The Page
if (!empty($remote_port)) {
    echo "Source Port: $remote_port \r\n";
} else {
    $missing_info[] = 'Source Port';
}

// Display User Agent Header That Was Sent By The Browser/Client
if (!empty($http_user_agent)) {
    echo "Browser User-Agent: $http_user_agent \r\n";
} else {
    $missing_info[] = 'Browser User-Agent';
}

// Combine & Display AS with it's Geographic information if we have a match
echo "\r\n[ ISP / Transit & Geo Information ] \r\n";
if (!empty($location) && $location !== ', ') {
    echo "Location: $location \r\n";
} else {
    $missing_info[] = 'Location';
}

// Continue ASN Lookup and display the info about the company and AS Number, etc..
if (!empty($asn) && !empty($as_name) && !empty($as_domain)) {
    echo "AS/Hosting Company Name: $as_name \r\n";
    echo "AS/Hosting Company Domain: $as_domain \r\n";
    echo "AS Number: $asn \r\n";
} else {
    $missing_info[] = 'ASN Information';
}

// Added for /startpage in 2026: Validation to let you know if you're coming from a Tor exit node. Yay.

echo "\r\n[ Tor Network Usage Check ]\r\n";

$tor_list_url = 'https://check.torproject.org/torbulkexitlist';
$cache_file = '/tmp/torbulkexitlist.cache';          // Ensure this path is writable by www-data if you move it
$cache_time = 3600;                                 // Cache for 1 hour in case someone is being dumb

if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
    $tor_exits = file($cache_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
} else {
    $tor_exits_raw = @file_get_contents($tor_list_url);
    if ($tor_exits_raw !== false) {
        $tor_exits = explode("\n", trim($tor_exits_raw));
        file_put_contents($cache_file, $tor_exits_raw);
    } else {
        $tor_exits = [];
        echo "Warning: Unable to fetch current Tor exit node list from torproject.org (connectivity issue).\r\n";

        $missing_info[] = 'Tor Exit List From torproject.org currently unavailable';
    }
}

if (in_array($remote_addr, $tor_exits, true)) {
    echo "Congratulations! You are currently using the Tor network.\r\n";
    echo "(Your traffic is exiting via a known Tor exit node.)\r\n";
} else {
    echo "You are NOT currently using the Tor network.\r\n";
    echo "(Your IP does not match any known Tor exit node.)\r\n";
    $missing_info[] = 'Your IP Did Not Show Up In Any Known Tor Exit Lists';
}

// Put all of the stuff we've gathered into missing_info together and then display it at the bottom of the page.
if (!empty($missing_info)) {
    echo "\r\n\r\n[ Information That Was Not Available During Query ]\r\n<ul>\r\n";
    foreach ($missing_info as $info) {
        echo "  <li>$info</li>\r\n";
    }
    echo "</ul>\r\n";
}

// Footer Info For PACKET.TEL LLC
echo "\r\n\r\n";
echo "<center>\r\n";
echo "This IP Echo Server is part of IP.URLS.IS, a cluster of servers operated by PACKET.TEL LLC.\r\n";
echo 'Please visit <a href="https://packet.tel" target="_blank">packet.tel llc</a> for PRIVACY-FOCUSED Hosting, Cellular, VoIP & More.' . "\r\n";
echo "©MMXXVI PACKET.TEL LLC\r\n";
echo "</center>";
?>
