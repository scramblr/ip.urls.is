<?php
// Retrieve client IP address, preferring HTTP_X_FORWARDED_FOR if available
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$remote_addr = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '');
$remote_port = htmlspecialchars($_SERVER['REMOTE_PORT'] ?? '');
$remote_user = htmlspecialchars($_SERVER['REMOTE_USER'] ?? '');
$redirect_remote_user = htmlspecialchars($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
$http_user_agent = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '');

// Perform Lookup on PTR (Reverse DNS) Record for Remote IP Address
$remote_host = gethostbyaddr($remote_addr);
$remote_host = $remote_host !== $remote_addr ? htmlspecialchars($remote_host) : 'NO REVERSE DNS/PTR RECORD FOUND';

// Establish a home for missing information to be printed at the end of the output.
$missing_info = [];

// Convert IP address to a long integer for comparison
function ip_to_long($ip) {
    return sprintf('%u', ip2long($ip));
}

// Function to fetch geo-location from SQLite database
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

$db_file = '/var/www/database/country_asn.db';                  // Put this file outside of the webroot unless you want tons of people exhausting ur bandwidth within a few days...
$geo_data = get_geo_location($remote_addr, $db_file);

/* Let's start matching the IP to our master geo file. */
$country_name = $geo_data['Country_Name'] ?? '';
$continent_name = $geo_data['Continent_Name'] ?? '';
$location = "$country_name, $continent_name";

$asn = $geo_data['ASN'] ?? '';
$as_name = $geo_data['AS_Name'] ?? '';
$as_domain = $geo_data['AS_Domain'] ?? '';

// Output the stuff we've gathered and tag the IP in the server's <title> so people can 1. easily parse to get their ip and 2. so we see the IP in all web scrapes like Google, Bing, etc ;)
echo "<title>IP: $client_ip - IP.URLS.IS IP Echo Server by PACKET.TEL LLC</title><pre>\r\n";
echo "[ Detected Browser Information ] \r\n";

// Display Client IP if available
if (!empty($client_ip)) {
    echo "CLIENT_IP Address: $client_ip \r\n";
}

// Display remote address if available
if (!empty($remote_addr)) {
    echo "REMOTE_ADDR Address: $remote_addr \r\n";
} else {
    $missing_info[] = 'Your IP Address';
}

// Display Remote IP's Reverse DNS if Available:
if (!empty($remote_host) && $remote_host !== 'No PTR Record Found \r\n') {      // Pretty sure this is redundant but w/e
    echo "Reverse DNS Hostname (PTR Record): $remote_host \r\n";                // Taking the output from the gethostbyname above, or dumping the IP here.
} else {
    $missing_info[] = 'Hostname (PTR Record)';          // Add it to the pile of missing stuff if we can't find it...
}

// Display Remote Machine's Source Port
if (!empty($remote_port)) {
    echo "Source Port: $remote_port \r\n";
} else {
    $missing_info[] = 'Source Port';                    // Dont have it? You know where it's going...
}

// Display User Agent We Were Sent
if (!empty($http_user_agent)) {
    echo "Browser User-Agent: $http_user_agent \r\n";
} else {
    $missing_info[] = 'Browser User-Agent';
}

// Display AS and Geo information if available
    echo "\r\n
[ ISP / Transit & Geo Information ] \r\n";
if (!empty($location) && $location !== ', ') {
    echo "Location: $location \r\n";
} else {
    $missing_info[] = 'Location';
}
// Continuing With ASN Lookup...
if (!empty($asn) && !empty($as_name) && !empty($as_domain)) {
    echo "AS/Hosting Company Name: $as_name \r\n";
    echo "AS/Hosting Company Domain: $as_domain \r\n";
    echo "AS Number: $asn \r\n";
} else {
    $missing_info[] = 'ASN Information';
}

// Check for all dat missing shiz and display it (if anything is there..)
if (!empty($missing_info)) {
    echo "\r\n\r\n
[ Information That Was Not Available During Query ]
<ul> \r\n";
    foreach ($missing_info as $info) {
        echo "$info \r\n";
    }
    echo "\r\n</ul>";
}

// Footer Info For PACKET.TEL LLC
echo "\r\n\r\n";
echo "<center>
This IP Echo Server is part of IP.URLS.IS, a cluster of servers operated by PACKET.TEL LLC.\r\n";
echo 'Please visit <a href="https://packet.tel" target="_blank">packet.tel llc</a> for PRIVACY-FOCUSED Hosting, Cellular, VoIP & More.';
echo "\r\n©MMXXVI PACKET.TEL LLC\r\n</center>";
