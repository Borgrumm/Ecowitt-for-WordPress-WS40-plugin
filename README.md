# Ecowitt-for-WordPress-WS40-plugin
This is the code needed to enable Ecowitt Gateway in Wordpress WS40 weather plugin.

The ecowitt-upload.php is the connector called by the GW2000/3000.
The generate-realtime.php is therealtime.txt generator.

These files should be placed in a dedicated directory of your web server (like /wp-content/uploads/meteo).
They create 2 folder if they does not exist:
  data => to store received and generated data
  log => to store work logs
No cleaning of the created files is done, you must handle that.


## ecowitt-upload.php

This PHP program is the connector needed to receive the Ecowitt Data.

It must be called by the gateway and create 2 files under the data folder:
    - latest.json => the latest received data in json format
    - raw-YYYY-MM-DD.log => the daily agregated data in one file.

Gateway Settings under "Weather Services" menu :
    Customized
        Customized: Enable
        Protocol Type Same As: Ecowitt
        Server IP / Hostname: Your-Host-IP-Address 
        Path: /the/path/for/ecowitt-upload.php
        Port: 80  (I am not sure that https is supported by Ecowitt Gateway so you shoult use http)
        Upload Interval: 60 (fastest timing is not needed)

Security settings:
    After the first call of ecowitt-upload, you must edit the json file and lookfor "PASSKEY": in the raw section.
    Copy the key and past it in the $EXPECTED_PASSKEY variable of this program (see bellow) to secure the communication and avoid external data injection.

If you do not wish to integrate data in a WS40 suitable format (meteobridge realtime) you must comment the last line "//require __DIR__ . '/generate-realtime.php';"

## generate-realtime.php

This PHP program is the realtime.txt file generator.

It take the file latest.json => the latest received data from Ecowitt Gateway in json format
and output the realtime.txt file for WS40.

It could be called by a cron task or by the ecowitt-upload.php file.
The advantage of this second option is that the realtime.txt file is updated on each data upload.
