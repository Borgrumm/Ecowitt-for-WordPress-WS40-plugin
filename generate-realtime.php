<?php
/*
*******************************************************************************
* METEOBRIDGE REALTIME FILE GENERATOR FOR WS40 WORPRESS PLUGIN                *
*******************************************************************************

Copyright 2026 Gael PHILIPPOT gael@philippot-huang.com

This code is available under GNU General Public License v2.0.

*******************************************************************************
This PHP program is the realtime.txt file generator.

It take the file latest.json => the latest received data from Ecowitt Gateway in json format
and output the realtime.txt file for WS40.

It could be called by a cron task or by the ecowitt-upload.php file.
The advantage of this second option is that the realtime.txt file is updated on each data upload.

*******************************************************************************
Version:
    1.0 :   2026-07-03  First Release

*/

declare(strict_types=1);

date_default_timezone_set('Europe/Paris');

$DATA_DIR = __DIR__ . '/data';
$LOG_DIR = __DIR__ . '/log';

if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}

if (!is_dir($LOG_DIR)) {
    mkdir($LOG_DIR, 0755, true);
}

$LATEST_FILE = $DATA_DIR . '/latest.json';
$STATE_FILE  = $DATA_DIR . '/realtime-state.json';
$OUTPUT_FILE = $DATA_DIR . '/realtime.txt';

if (!is_file($LATEST_FILE)) {
    http_response_code(500);
    log_action("generate-realtime - latest.json not found");
    exit("latest.json not found\n");
}

$data = json_decode(file_get_contents($LATEST_FILE), true);
if (!is_array($data)) {
    http_response_code(500);
    log_action("generate-realtime - latest.json invalide");
    exit("latest.json invalide\n");
}

$m = $data['metric'] ?? [];
$r = $data['raw'] ?? [];

function log_action(string $action) {
  global $LOG_DIR;
  $log  = date("Y-m-d H:i:s").' - ' . $action.PHP_EOL;
  //Save string to log, use FILE_APPEND to append.
  file_put_contents(
    $LOG_DIR . '/' . gmdate('Y-m-d') . '.log',
    $log,
    FILE_APPEND | LOCK_EX
    );
}

function v(array $a, string $key, float $default = 0.0): float {
    return isset($a[$key]) && is_numeric($a[$key]) ? (float)$a[$key] : $default;
}

function n(?float $value, int $decimals = 1): string {
    return number_format($value ?? 0.0, $decimals, '.', '');
}

function compass(?float $deg): string {
    if ($deg === null) return '---';

    $dirs = [
        'N', 'NNE', 'NE', 'ENE',
        'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW',
        'W', 'WNW', 'NW', 'NNW'
    ];

    $i = (int)round(((float)$deg % 360) / 22.5) % 16;
    return $dirs[$i];
}

function beaufort_from_kmh(float $kmh): int {
    $limits = [1, 6, 12, 20, 29, 39, 50, 62, 75, 89, 103, 118];
    foreach ($limits as $i => $limit) {
        if ($kmh < $limit) return $i;
    }
    return 12;
}

function dewpoint_c(float $tempC, float $humidity): float {
    if ($humidity <= 0) return $tempC;

    $a = 17.27;
    $b = 237.7;
    $alpha = (($a * $tempC) / ($b + $tempC)) + log($humidity / 100);

    return ($b * $alpha) / ($a - $alpha);
}

function windchill_c(float $tempC, float $windKmh): float {
    if ($tempC > 10 || $windKmh <= 4.8) return $tempC;

    return 13.12
        + 0.6215 * $tempC
        - 11.37 * pow($windKmh, 0.16)
        + 0.3965 * $tempC * pow($windKmh, 0.16);
}

function heatindex_c(float $tempC, float $humidity): float {
    $tempF = ($tempC * 9 / 5) + 32;

    if ($tempF < 80) {
        return $tempC;
    }

    $hiF =
        -42.379
        + 2.04901523 * $tempF
        + 10.14333127 * $humidity
        - 0.22475541 * $tempF * $humidity
        - 0.00683783 * $tempF * $tempF
        - 0.05481717 * $humidity * $humidity
        + 0.00122874 * $tempF * $tempF * $humidity
        + 0.00085282 * $tempF * $humidity * $humidity
        - 0.00000199 * $tempF * $tempF * $humidity * $humidity;

    return ($hiF - 32) * 5 / 9;
}

function inch_to_mm(float $inch): float {
    return $inch * 25.4;
}

$now = time();
$today = date('Y-m-d', $now);
$timeHM = date('H:i', $now);

$tempC      = v($m, 'temperature_c');
$hum        = v($m, 'humidity_pct');
$dewC       = dewpoint_c($tempC, $hum);
$windKmh    = v($m, 'wind_speed_kmh');
$gustKmh    = v($m, 'wind_gust_kmh');
$windDir    = v($m, 'wind_direction_deg');
$rainRate   = v($m, 'rain_rate_mm_h');
$rainDay    = v($m, 'rain_day_mm');
$rainMonth  = v($m, 'rain_month_mm');
$rainYear   = v($m, 'rain_year_mm');
$rainWeek   = v($m, 'rain_week_mm');
$pressure   = v($m, 'pressure_relative_hpa');
$solar      = v($m, 'solar_radiation_wm2');
$uv         = v($m, 'uv_index');
$indoorTemp = v($m, 'indoor_temperature_c', $tempC);
$indoorHum  = v($m, 'indoor_humidity_pct', $hum);

$hourlyRain = isset($r['hourlyrainin']) && is_numeric($r['hourlyrainin'])
    ? inch_to_mm((float)$r['hourlyrainin'])
    : 0.0;

$state = [];
if (is_file($STATE_FILE)) {
    $state = json_decode(file_get_contents($STATE_FILE), true) ?: [];
}

if (($state['date'] ?? '') !== $today) {
    $state = [
        'date' => $today,
        'yesterday_rain_mm' => $state['rain_day_mm'] ?? 0.0,

        'temp_high' => $tempC,
        'temp_high_time' => $timeHM,
        'temp_low' => $tempC,
        'temp_low_time' => $timeHM,

        'wind_high' => $windKmh,
        'wind_high_time' => $timeHM,
        'gust_high' => $gustKmh,
        'gust_high_time' => $timeHM,

        'pressure_high' => $pressure,
        'pressure_high_time' => $timeHM,
        'pressure_low' => $pressure,
        'pressure_low_time' => $timeHM,

        'windrun_km' => 0.0,
        'last_seen' => $now,
        'rain_day_mm' => $rainDay,
    ];
}

$deltaHours = max(0, min(1, ($now - (int)($state['last_seen'] ?? $now)) / 3600));
$state['windrun_km'] = (float)($state['windrun_km'] ?? 0.0) + ($windKmh * $deltaHours);
$state['last_seen'] = $now;
$state['rain_day_mm'] = $rainDay;

if ($tempC > (float)$state['temp_high']) {
    $state['temp_high'] = $tempC;
    $state['temp_high_time'] = $timeHM;
}

if ($tempC < (float)$state['temp_low']) {
    $state['temp_low'] = $tempC;
    $state['temp_low_time'] = $timeHM;
}

if ($windKmh > (float)$state['wind_high']) {
    $state['wind_high'] = $windKmh;
    $state['wind_high_time'] = $timeHM;
}

if ($gustKmh > (float)$state['gust_high']) {
    $state['gust_high'] = $gustKmh;
    $state['gust_high_time'] = $timeHM;
}

if ($pressure > (float)$state['pressure_high']) {
    $state['pressure_high'] = $pressure;
    $state['pressure_high_time'] = $timeHM;
}

if ($pressure < (float)$state['pressure_low']) {
    $state['pressure_low'] = $pressure;
    $state['pressure_low_time'] = $timeHM;
}

$windChill = windchill_c($tempC, $windKmh);
$heatIndex = heatindex_c($tempC, $hum);
$feelsLike = $tempC <= 10 ? $windChill : $heatIndex;

$cloudBaseFt = max(0, (($tempC - $dewC) / 2.5) * 1000 * 3.28084);
$isSunny = $solar > 120 ? 1 : 0;

/*
 * Cumulus realtime.txt.
 * One line with space separator
 */
$fields = [
    date('d/m/y', $now),                  // 1 Date
    date('H:i:s', $now),                  // 2 Time
    n($tempC),                            // 3 Outdoor temp
    (string)round($hum),                  // 4 Outdoor humidity
    n($dewC),                             // 5 Dew point
    n($windKmh),                          // 6 Wind speed average
    n($windKmh),                          // 7 Latest wind speed
    (string)round($windDir),              // 8 Wind bearing
    n($rainRate),                         // 9 Rain rate
    n($rainDay),                          // 10 Rain today
    n($pressure),                         // 11 Pressure
    compass($windDir),                    // 12 Current wind direction
    (string)beaufort_from_kmh($windKmh),  // 13 Beaufort
    'km/h',                               // 14 Wind unit
    'C',                                  // 15 Temp unit
    'hPa',                                // 16 Pressure unit
    'mm',                                 // 17 Rain unit
    n((float)$state['windrun_km']),       // 18 Wind run today
    n(0.0, 1),                            // 19 Pressure trend
    n($rainMonth),                        // 20 Monthly rain
    n($rainYear),                         // 21 Yearly rain
    n((float)($state['yesterday_rain_mm'] ?? 0.0)), // 22 Yesterday rain
    n($indoorTemp),                       // 23 Indoor temp
    (string)round($indoorHum),            // 24 Indoor humidity
    n($windChill),                        // 25 Wind chill
    n(0.0, 1),                            // 26 Temp trend
    n((float)$state['temp_high']),        // 27 High temp today
    $state['temp_high_time'],             // 28 Time high temp
    n((float)$state['temp_low']),         // 29 Low temp today
    $state['temp_low_time'],              // 30 Time low temp
    n((float)$state['wind_high']),        // 31 High wind speed today
    $state['wind_high_time'],             // 32 Time high wind
    n((float)$state['gust_high']),        // 33 High gust today
    $state['gust_high_time'],             // 34 Time high gust
    n((float)$state['pressure_high']),    // 35 High pressure
    $state['pressure_high_time'],         // 36 Time high pressure
    n((float)$state['pressure_low']),     // 37 Low pressure
    $state['pressure_low_time'],          // 38 Time low pressure
    'GW3000',                             // 39 Version/source
    '1',                                  // 40 Build
    n($gustKmh),                          // 41 10-min high gust / current gust fallback
    n($heatIndex),                        // 42 Heat index
    n($tempC),                            // 43 Humidex fallback
    n($uv, 1),                            // 44 UV
    n(0.0, 1),                            // 45 ET today
    (string)round($solar),                // 46 Solar radiation
    (string)round($windDir),              // 47 Average bearing
    n($hourlyRain),                       // 48 Rain last hour
    '0',                                  // 49 Forecast number
    '1',                                  // 50 Is daylight
    '0',                                  // 51 Sensor contact lost
    compass($windDir),                    // 52 Average wind direction
    (string)round($cloudBaseFt),          // 53 Cloud base
    'ft',                                 // 54 Cloud base unit
    n($feelsLike),                        // 55 Apparent temp
    n(0.0, 1),                            // 56 Sunshine hours
    (string)round(max($solar, 0)),        // 57 Current solar max fallback
    (string)$isSunny,                     // 58 Is sunny
    n($feelsLike),                        // 59 Feels like
    n($rainWeek),                         // 60 Rain week, Cumulus MX r�cent
];

file_put_contents($STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($OUTPUT_FILE, implode(' ', $fields) . "\n", LOCK_EX);

header('Content-Type: text/plain; charset=utf-8');
log_action("generate-realtime - Work done");
echo "OK\n";