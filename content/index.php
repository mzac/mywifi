<?php

ini_set('display_errors', 'On');

// Config File
require("/opt/config/config.php");

// Timezone
date_default_timezone_set('America/Montreal');

// Get current IP address of the client
$current_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];

if(isset($_POST['username']) && isset($_POST['password'])){

    $adServer = "$ldap_server";

    $ldap = ldap_connect($adServer);
    $username = $_POST['username'];
    $password = $_POST['password'];

    $ldaprdn = $ldap_domain . "\\" . $username;

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

    $bind = @ldap_bind($ldap, $username, $password);

    if ($bind) {
        $filter="(userPrincipalName=$username)";
        $result = ldap_search($ldap,$ldap_base_dn,$filter);
        ldap_sort($ldap,$result,"sn");
        $info = ldap_get_entries($ldap, $result);
        for ($i=0; $i<$info["count"]; $i++)
        {
            if($info['count'] > 1)
                break;
            $upn = $info[$i]["userprincipalname"][0];
            $sam = $info[$i]["samaccountname"][0];
            $first_name = $info[$i]["givenname"][0];
            $last_name = $info[$i]["sn"][0];
            $department = $info[$i]["department"][0];
        }
        @ldap_close($ldap);

        // --------------------------------------------------------------------------------
        // Connect to ClearpassDB
        $dbconn = pg_connect("host=$clearpass_server dbname=$clearpass_db_insight user=$clearpass_user password=$clearpass_pass")
            or die('Could not connect: ' . pg_last_error());

        // --------------------------------------------------------------------------------
        // Query for live wireless connections
        $live_query = "SELECT DISTINCT
                TO_CHAR(radius_acct.start_time, 'YYYY-MM-DD HH:MI:SS AM') AS \"Start Time\",
                radius_acct.username AS \"Username\",
                UPPER(
                    CONCAT(
                        SUBSTRING(radius_acct.calling_station_id, 1, 2), ':',
                        SUBSTRING(radius_acct.calling_station_id, 3, 2), ':',
                        SUBSTRING(radius_acct.calling_station_id, 5, 2), ':',
                        SUBSTRING(radius_acct.calling_station_id, 7, 2), ':',
                        SUBSTRING(radius_acct.calling_station_id, 9, 2), ':',
                        SUBSTRING(radius_acct.calling_station_id, 11)
                    )
                ) AS \"Mac Address\",
                endpoints.hostname AS \"Device Name\",
                endpoints.device_name AS \"Device Type\",
                radius_acct.framed_ip AS \"IP Address\",
                radius_acct.ap_name AS \"Access Point\",
                radius_acct.ssid AS \"SSID\"

                FROM radius_acct

                LEFT JOIN endpoints
                ON radius_acct.calling_station_id = endpoints.mac

                WHERE 
                    (radius_acct.username = '$upn' OR radius_acct.username = '$sam')
                    AND start_time <=  NOW()
                    AND radius_acct.end_time is NULL
                    AND radius_acct.nas_port_type = 'Wireless-802.11'
                ORDER BY 1 DESC
                ";

        // --------------------------------------------------------------------------------
        // Query for User Devices
        $device_query = "SELECT DISTINCT 
                TO_CHAR(endpoints.updated_at, 'YYYY-MM-DD HH:MI:SS AM') AS \"Last Seen\",
                TO_CHAR(endpoints.added_at, 'YYYY-MM-DD HH:MI:SS AM') AS \"First Seen\",
                endpoints.username AS \"Username\",
            	UPPER(
                    CONCAT(
                        SUBSTRING(auth.mac, 1, 2), ':',
                        SUBSTRING(auth.mac, 3, 2), ':',
                        SUBSTRING(auth.mac, 5, 2), ':',
                        SUBSTRING(auth.mac, 7, 2), ':',
                        SUBSTRING(auth.mac, 9, 2), ':',
                        SUBSTRING(auth.Mac, 11)
                    )
                ) AS \"Mac Address\",
                endpoints.hostname AS \"Device Name\",
	            endpoints.device_name AS \"Device Type\",
	            endpoints.ap AS \"Last Access Point\",
	            endpoints.ssid AS \"Last SSID\"

                FROM auth 

                LEFT JOIN endpoints 
                ON auth.mac = endpoints.mac 

                WHERE 
                    (auth.username = '$upn' OR auth.username = '$sam')
	                AND timestamp > current_timestamp - INTERVAL '30 DAYS'
	                AND auth.mac IS NOT NULL

                ORDER BY 1 DESC
                ";

        // --------------------------------------------------------------------------------
        // Query for User Log
        $log_query = "SELECT DISTINCT
                TO_CHAR(auth.timestamp, 'YYYY-MM-DD HH:MI:SS AM') AS \"Start Time\",
                auth.username AS \"Username\",
                UPPER(
                    CONCAT(
                        SUBSTRING(auth.mac, 1, 2), ':',
                        SUBSTRING(auth.mac, 3, 2), ':',
                        SUBSTRING(auth.mac, 5, 2), ':',
                        SUBSTRING(auth.mac, 7, 2), ':',
                        SUBSTRING(auth.mac, 9, 2), ':',
                        SUBSTRING(auth.Mac, 11)
                    )
                ) AS \"Mac Address\",
                endpoints.hostname AS \"Device Name\",
                endpoints.device_name AS \"Device Type\",
                --radius_acct.framed_ip AS \"IP Address\",
                auth.ap_name AS \"Access Point\",
                auth.ssid AS \"SSID\",
                CASE
                    WHEN auth.auth_status = 'User' THEN 'Success'
                    WHEN auth.auth_status = 'Failed' THEN 'Failed'
                    ELSE 'Other'
                END	AS \"Auth Int\"
      
                FROM auth
      
                LEFT JOIN endpoints
                    ON auth.mac = endpoints.mac
                LEFT JOIN radius_acct
                    ON auth.timestamp = radius_acct.start_time
                       AND auth.mac = radius_acct.calling_station_id
      
                WHERE
                    timestamp > current_timestamp - INTERVAL '1 DAY'
                    AND ( auth.auth_status = 'User' OR auth.auth_status = 'Failed')
                    AND (auth.username = '$upn' OR auth.username = '$sam')

                ORDER BY 1 DESC
                ";

        // --------------------------------------------------------------------------------

        // Performing SQL queries
        $live_result = pg_query($live_query) or die('Query failed: ' . pg_last_error());
        $device_result = pg_query($device_query) or die('Query failed: ' . pg_last_error());
        $log_result = pg_query($log_query) or die('Query failed: ' . pg_last_error());

        // Print Header
        echo "<html>\n";
        echo "<head>\n";
        echo "<title>MyWifi</title>\n";
        echo "</head>\n";
        echo "<body>\n";
        
        // User Information
        echo "Username: $upn / $sam<br>\n";
        echo "First Name: $first_name<br>\n";
        echo "Last Name: $last_name<br>\n";
        echo "Department: $department<br>\n";
        echo "Current IP: $current_ip<br>\n";
        echo "Last Updated: ". date('Y-m-d h:i:s A T') ."<br>\n";
        echo "<p>\n";

        // --------------------------------------------------------------------------------
        // Live Connections
        echo "My Live Connections<br>\n";
        echo "<table border=1 cellpadding=2>\n";

        echo "<tr>
            <td>Start Time</td>
            <td>Username</td>
            <td>Mac Address</td>
            <td>Hostname</td>
            <td>Device Type</td>
            <td>IP Address</td>
            <td>Access Point</td>
            <td>SSID
            </td></tr>";
            
        while ($line = pg_fetch_array($live_result, null, PGSQL_ASSOC)) {
            echo "\t<tr>\n";
            foreach ($line as $col_value) {
                if ($col_value == $current_ip) {
                    echo "\t\t<td bgcolor=\"#00BDFF\">$col_value</td>\n";
                } else {
                    echo "\t\t<td>$col_value</td>\n";
                }
            }
            echo "\t</tr>\n";
        }
        echo "</table>\n";
        echo "<p>\n";

        // --------------------------------------------------------------------------------
        // User Devices
        echo "My Devices (seen in the last 30 days)<br>\n";
        echo "<table border=1 cellpadding=2>\n";

        echo "<tr>
            <td>Last Seen</td>
            <td>First Seen</td>
            <td>Username</td>
            <td>Mac Address</td>
            <td>Hostname</td>
            <td>Device Type</td>
            <td>Last Access Point</td>
            <td>Last SSID</td>
            </tr>";
            
        while ($line = pg_fetch_array($device_result, null, PGSQL_ASSOC)) {
            echo "\t<tr>\n";
            foreach ($line as $col_value) {
                echo "\t\t<td>$col_value</td>\n";
            }
            echo "\t</tr>\n";
        }
        echo "</table>\n";
        echo "<p>\n";

        // --------------------------------------------------------------------------------
        // User Log
        echo "My Authentication Log (seen in the last 24 hours)<br>\n";
        echo "<table border=1 cellpadding=2>\n";

        echo "<tr>
            <td>Start Time</td>
            <td>Username</td>
            <td>Mac Address</td>
            <td>Hostname</td>
            <td>Device Type</td>
            <td>Access Point</td>
            <td>SSID</td>
            <td>Authentication</td>
            </tr>";
            
        while ($line = pg_fetch_array($log_result, null, PGSQL_ASSOC)) {
            echo "\t<tr>\n";
            foreach ($line as $col_value) {
                if ($col_value == "Failed") {
                    echo "\t\t<td bgcolor=\"#FF0000\">$col_value</td>\n";
                } elseif ($col_value == "Success") {
                    echo "\t\t<td bgcolor=\"#00FF00\">$col_value</td>\n";
                } else {
                    echo "\t\t<td>$col_value</td>\n";
                }
            }
            echo "\t</tr>\n";
        }
        echo "</table>\n";

        // Debug
        print "<p hidden>\n";
        print "Headers:\n";
        foreach (getallheaders() as $name => $value) {
            echo "$name: $value\n";
        }
        print "<p>\n";

        // Print Footer
        echo "</body>\n";
        echo "</html>\n";

        // --------------------------------------------------------------------------------
        // Free resultset
        pg_free_result($live_result);
        pg_free_result($device_result);
        pg_free_result($log_result);

        // --------------------------------------------------------------------------------
        // Closing connection
        pg_close($dbconn);

    } else {
        $msg = "Invalid username / password";
        echo $msg;
    }

}else{
?>

    <H1>MyWifi Portal</H1>
    <form action="#" method="POST">
        <table>
            <tr><td><label for="username">Username: </label></td><td><input id="username" type="text" name="username" /><br></td></tr>
            <tr><td><label for="password">Password: </label></td><td><input id="password" type="password" name="password" /><p></td></tr>
        </table>
        <input type="submit" name="submit" value="Submit" />
    </form>
<?php } ?>
