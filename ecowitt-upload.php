<?php
/*
*******************************************************************************
* ECOWITT UPLOADER for GW2000 / GW3000 and compatible Ecowitt gateways        *
*******************************************************************************

Copyright 2026 Gael PHILIPPOT gael@philippot-huang.com

This code is available under GNU General Public License v2.0.

*******************************************************************************
Purpose
*******************************************************************************
This program receives Ecowitt custom-server uploads, validates the PASSKEY,
normalizes the raw payload with a generic alias map, and writes:

    - data/latest.json          latest structured data
    - data/raw-YYYY-MM-DD.log   daily raw payload log, optional
    - log/YYYY-MM-DD.log        action/error log, optional

Gateway settings under "Weather Services" menu:
    Customized
        Customized: Enable
        Protocol Type Same As: Ecowitt
        Server IP / Hostname: your host or IP
        Path: /the/path/for/ecowitt-upload.php
        Port: 80
        Upload Interval: 60

Security:
    The expected PASSKEY is no longer stored in this PHP file.
    It is read from config.ini:
        [security]
        expected_passkey = "YOUR_GATEWAY_PASSKEY"

Mapping:
    Metric extraction is driven by ecowitt-map.json. This allows the script to
    support different Ecowitt stations/sensors without editing PHP code each time.

Realtime / WS40 integration:
    If you generate realtime.txt for WS40/Cumulus/Meteobridge compatibility,
    configure the [realtime] section in config.ini.

*******************************************************************************
Version:
    2.00  :  2026-07-07  Generic config.ini + ecowitt-map.json architecture
                            - PASSKEY and paths moved to config.ini
                            - alias-based metric extraction
                            - dynamic sensor extraction
                            - profile and feature detection
                            - unknown raw key detection
                            - safer numeric parsing for tiny values like 0.024
    1.01  :  2026-07-04  Minor correction for logging purpose
    1.00  :  2026-07-03  First release

*/

declare(strict_types=1);

namespace EcowittUpload;

const VERSION = '2.00';
const DEFAULT_CONFIG_FILE = 'config.ini';
const DEFAULT_MAP_FILE = 'ecowitt-map.json';

main();

function main(): void
{
    $baseDir = __DIR__;

    try {
        $config = load_config($baseDir);

        $timezone = cfg($config, 'general', 'timezone', 'UTC');
        if (is_string($timezone) && $timezone !== '') {
            date_default_timezone_set($timezone);
        } else {
            date_default_timezone_set('UTC');
        }

        $dataDir = resolve_path((string)cfg($config, 'paths', 'data_dir', 'data'), $baseDir);
        $logDir  = resolve_path((string)cfg($config, 'paths', 'log_dir', 'log'), $baseDir);
        ensure_dir($dataDir);
        ensure_dir($logDir);

        $writeActionLog = cfg_bool($config, 'logging', 'write_action_log', true);
        $writeRawDailyLog = cfg_bool($config, 'logging', 'write_raw_daily_log', true);
        $logUnknownKeys = cfg_bool($config, 'logging', 'log_unknown_keys', true);

        $mappingPath = resolve_path((string)cfg($config, 'paths', 'mapping_file', DEFAULT_MAP_FILE), $baseDir);
        $mapping = load_json_file($mappingPath);

        $payload = read_payload();
        if ($payload === []) {
            http_response_code(400);
            action_log($logDir, 'Ecowitt-Upload V2 - No data', $writeActionLog);
            respond('NO DATA', 400);
        }

        $expectedPasskey = (string)cfg($config, 'security', 'expected_passkey', '');
        if ($expectedPasskey !== '' && (($payload['PASSKEY'] ?? '') !== $expectedPasskey)) {
            http_response_code(403);
            action_log($logDir, 'Ecowitt-Upload V2 - Bad passkey', $writeActionLog);
            respond('BAD PASSKEY', 403);
        }

        $includeRawPayload = cfg_bool($config, 'general', 'include_raw_payload', true);
        $includeSourceKeys = cfg_bool($config, 'general', 'include_source_keys', true);
        $redactPasskey = cfg_bool($config, 'security', 'redact_passkey_in_outputs', true);

        $usedKeys = [];
        $sourceKeys = [];

        $metric = build_metric($payload, $mapping, $usedKeys, $sourceKeys);
        $profile = build_profile($payload, $mapping, $usedKeys);
        $unknownRawKeys = detect_unknown_raw_keys($payload, $mapping);

        if ($includeSourceKeys) {
            $profile['metric_source_keys'] = $sourceKeys;
        }
        $profile['unknown_raw_key_count'] = count($unknownRawKeys);

        $safePayload = sanitize_payload($payload, $redactPasskey);

        $output = [
            'schema' => 'roccia-rosa.ecowitt.latest',
            'schema_version' => 2,
            'generated_by' => 'ecowitt-upload.php',
            'generator_version' => VERSION,
            'generated_at_utc' => gmdate('c'),
            'metric' => $metric,
            'profile' => $profile,
            'unknown_raw_keys' => $unknownRawKeys,
        ];

        if ($includeRawPayload) {
            $output['raw'] = $safePayload;
        }

        write_json_atomic($dataDir . '/latest.json', $output);

        if ($writeRawDailyLog) {
            append_line_atomic(
                $dataDir . '/raw-' . gmdate('Y-m-d') . '.log',
                json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            );
        }

        if ($logUnknownKeys && $unknownRawKeys !== []) {
            action_log(
                $logDir,
                'Ecowitt-Upload V2 - Unknown raw keys: ' . implode(', ', $unknownRawKeys),
                $writeActionLog
            );
        }

        action_log($logDir, 'Ecowitt-Upload V2 - Data received and latest.json updated', $writeActionLog);

        run_realtime_if_enabled($config, $baseDir, $logDir, $writeActionLog);

        respond('OK', 200);
    } catch (\Throwable $e) {
        error_log('Ecowitt-Upload V2 fatal error: ' . $e->getMessage());
        http_response_code(500);
        respond('SERVER ERROR', 500);
    }
}

function load_config(string $baseDir): array
{
    $configPath = getenv('ECOWITT_CONFIG');

    if (!is_string($configPath) || trim($configPath) === '') {
        $configPath = $baseDir . '/' . DEFAULT_CONFIG_FILE;
    }

    if (!is_file($configPath)) {
        throw new \RuntimeException('Configuration file not found: ' . $configPath);
    }

    $config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
    if (!is_array($config)) {
        throw new \RuntimeException('Invalid configuration file: ' . $configPath);
    }

    return $config;
}

function cfg(array $config, string $section, string $key, mixed $default = null): mixed
{
    return $config[$section][$key] ?? $default;
}

function cfg_bool(array $config, string $section, string $key, bool $default): bool
{
    $value = cfg($config, $section, $key, $default);

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return $value !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    return $default;
}

function resolve_path(string $path, string $baseDir): string
{
    if ($path === '') {
        return $baseDir;
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return rtrim($baseDir, '/') . '/' . $path;
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \RuntimeException('Unable to create directory: ' . $dir);
    }
}

function load_json_file(string $path): array
{
    if (!is_file($path)) {
        throw new \RuntimeException('JSON file not found: ' . $path);
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException('Unable to read JSON file: ' . $path);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \RuntimeException('Invalid JSON file ' . $path . ': ' . json_last_error_msg());
    }

    return $data;
}

function read_payload(): array
{
    // Development helper: php ecowitt-upload.php payload.json
    // The file may contain either a raw payload object or a latest.json-like {"raw": {...}} object.
    if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][1]) && is_file((string)$_SERVER['argv'][1])) {
        $json = json_decode((string)file_get_contents((string)$_SERVER['argv'][1]), true);
        if (is_array($json)) {
            return isset($json['raw']) && is_array($json['raw']) ? $json['raw'] : $json;
        }
    }

    $payload = $_POST ?: $_GET;

    if ($payload !== []) {
        return normalize_payload_values($payload);
    }

    $input = file_get_contents('php://input');
    if (!is_string($input) || trim($input) === '') {
        return [];
    }

    $trimmed = trim($input);
    if (str_starts_with($trimmed, '{')) {
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            return normalize_payload_values($json);
        }
    }

    parse_str($input, $parsed);
    return is_array($parsed) ? normalize_payload_values($parsed) : [];
}

function normalize_payload_values(array $payload): array
{
    $normalized = [];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            // Ecowitt custom uploads should not send arrays, but keep a safe representation.
            $normalized[(string)$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $normalized[(string)$key] = is_scalar($value) || $value === null ? (string)$value : '';
        }
    }

    return $normalized;
}

function number_or_null(array $data, string $key): ?float
{
    if (!array_key_exists($key, $data)) {
        return null;
    }

    $value = $data[$key];

    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $float = (float)$value;
        return is_finite($float) ? $float : null;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $lower = strtolower($text);
    if (in_array($lower, ['--', '---', 'null', 'nan', 'n/a', 'na'], true)) {
        return null;
    }

    // Accept decimal comma if a future locale/device ever sends it.
    $text = str_replace(',', '.', $text);

    if (!is_numeric($text)) {
        return null;
    }

    $float = (float)$text;
    return is_finite($float) ? $float : null;
}

function string_or_null(array $data, string $key): ?string
{
    if (!array_key_exists($key, $data)) {
        return null;
    }

    $text = trim((string)$data[$key]);
    return $text === '' ? null : $text;
}

function build_metric(array $payload, array $mapping, array &$usedKeys, array &$sourceKeys): array
{
    $metric = [
        'received_at_utc' => gmdate('c'),
        'station_time_utc' => $payload['dateutc'] ?? null,
        'stationtype' => $payload['stationtype'] ?? ($payload['softwaretype'] ?? null),
        'model' => $payload['model'] ?? null,
    ];

    foreach (($mapping['fields'] ?? []) as $metricName => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $result = extract_numeric_from_sources($payload, $definition, null);
        $metric[$metricName] = $result['found'] ? $result['value'] : null;

        if ($result['found'] && isset($result['source_key'])) {
            $usedKeys[$result['source_key']] = true;
            $sourceKeys[$metricName] = $result['source_key'];
        }
    }

    foreach (($mapping['dynamic_sensors'] ?? []) as $familyName => $familyDefinition) {
        if (!is_array($familyDefinition)) {
            continue;
        }

        $outputKey = (string)($familyDefinition['output_key'] ?? $familyName);
        $sensors = extract_dynamic_sensor_family($payload, $familyDefinition, $usedKeys, $sourceKeys, $outputKey);
        if ($sensors !== []) {
            $metric[$outputKey] = $sensors;
        }
    }

    return $metric;
}

function extract_dynamic_sensor_family(
    array $payload,
    array $familyDefinition,
    array &$usedKeys,
    array &$sourceKeys,
    string $outputKey
): array {
    $sensors = [];
    $channels = $familyDefinition['channels'] ?? [];
    $fieldDefinitions = $familyDefinition['fields'] ?? [];

    if (!is_array($channels) || !is_array($fieldDefinitions)) {
        return [];
    }

    foreach ($channels as $channel) {
        $channelText = (string)$channel;
        $sensor = [];

        foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }

            $result = extract_numeric_from_sources($payload, $fieldDefinition, $channelText);
            if ($result['found']) {
                $sensor[(string)$fieldName] = $result['value'];

                if (isset($result['source_key'])) {
                    $usedKeys[$result['source_key']] = true;
                    $sourceKeys[$outputKey . '.' . $channelText . '.' . $fieldName] = $result['source_key'];
                }
            }
        }

        if ($sensor !== []) {
            $sensors[$channelText] = $sensor;
        }
    }

    return $sensors;
}

function extract_numeric_from_sources(array $payload, array $definition, ?string $channel): array
{
    foreach (($definition['sources'] ?? []) as $source) {
        if (!is_array($source)) {
            continue;
        }

        $key = source_key($source, $channel);
        if ($key === null || !array_key_exists($key, $payload)) {
            continue;
        }

        $value = number_or_null($payload, $key);
        if ($value === null) {
            continue;
        }

        $fromUnit = (string)($source['unit'] ?? 'raw');
        $targetUnit = (string)($definition['target_unit'] ?? $fromUnit);
        $converted = convert_unit($value, $fromUnit, $targetUnit);

        $decimals = (int)($definition['decimals'] ?? 2);
        $converted = round_value($converted, $decimals);

        return [
            'found' => true,
            'value' => $converted,
            'source_key' => $key,
            'source_unit' => $fromUnit,
            'target_unit' => $targetUnit,
        ];
    }

    return ['found' => false, 'value' => null];
}

function source_key(array $source, ?string $channel): ?string
{
    if (isset($source['key'])) {
        return (string)$source['key'];
    }

    if (isset($source['key_pattern']) && $channel !== null) {
        return str_replace('{channel}', $channel, (string)$source['key_pattern']);
    }

    return null;
}

function convert_unit(float $value, string $from, string $to): float
{
    if ($from === $to || $to === '' || $from === 'raw' || $to === 'raw') {
        return $value;
    }

    return match ($from . '>' . $to) {
        'F>C' => ($value - 32.0) * 5.0 / 9.0,
        'C>F' => ($value * 9.0 / 5.0) + 32.0,
        'inHg>hPa' => $value * 33.8638866667,
        'hPa>inHg' => $value / 33.8638866667,
        'mph>km/h' => $value * 1.609344,
        'km/h>mph' => $value / 1.609344,
        'm/s>km/h' => $value * 3.6,
        'km/h>m/s' => $value / 3.6,
        'inch>mm' => $value * 25.4,
        'mm>inch' => $value / 25.4,
        'inch/h>mm/h' => $value * 25.4,
        'mm/h>inch/h' => $value / 25.4,
        'mi>km' => $value * 1.609344,
        'km>mi' => $value / 1.609344,
        default => $value,
    };
}

function round_value(float $value, int $decimals): int|float
{
    if ($decimals <= 0) {
        return (int)round($value);
    }

    return round($value, $decimals);
}

function build_profile(array $payload, array $mapping, array &$usedKeys): array
{
    $profileDefinition = $mapping['profile'] ?? [];
    $profile = [
        'mapping_schema_version' => $mapping['schema_version'] ?? null,
        'mapping_name' => $mapping['name'] ?? null,
        'mapping_generated_at_utc' => $mapping['generated_at_utc'] ?? null,
    ];

    foreach (($profileDefinition['metadata_fields'] ?? []) as $name => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $source = (string)($definition['source'] ?? '');
        if ($source === '') {
            continue;
        }

        $value = extract_profile_value($payload, $definition);
        $profile[(string)$name] = $value;

        if (array_key_exists($source, $payload)) {
            $usedKeys[$source] = true;
        }
    }

    $features = [];
    foreach (($profileDefinition['feature_rules'] ?? []) as $rule) {
        if (is_array($rule) && feature_rule_matches($payload, $rule)) {
            $features[] = (string)($rule['name'] ?? 'unnamed_feature');
        }
    }
    $profile['detected_features'] = $features;

    return $profile;
}

function extract_profile_value(array $payload, array $definition): string|int|float|null
{
    $source = (string)($definition['source'] ?? '');
    $type = (string)($definition['type'] ?? 'string');

    if ($source === '' || !array_key_exists($source, $payload)) {
        return null;
    }

    return match ($type) {
        'number' => number_or_null($payload, $source),
        'integer' => ($tmp = number_or_null($payload, $source)) === null ? null : (int)round($tmp),
        'regex' => extract_regex_value((string)$payload[$source], (string)($definition['pattern'] ?? '')),
        'datetime_string', 'string' => string_or_null($payload, $source),
        default => string_or_null($payload, $source),
    };
}

function extract_regex_value(string $text, string $pattern): ?string
{
    if ($pattern === '') {
        return null;
    }

    $delimiter = '~';
    $safePattern = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter;
    if (@preg_match($safePattern, $text, $matches) !== 1) {
        return null;
    }

    if (isset($matches['version'])) {
        return (string)$matches['version'];
    }

    return isset($matches[1]) ? (string)$matches[1] : (string)$matches[0];
}

function feature_rule_matches(array $payload, array $rule): bool
{
    if (isset($rule['match_any_keys']) && is_array($rule['match_any_keys'])) {
        foreach ($rule['match_any_keys'] as $key) {
            if (array_key_exists((string)$key, $payload)) {
                return true;
            }
        }
    }

    if (isset($rule['match_all_keys']) && is_array($rule['match_all_keys'])) {
        $all = true;
        foreach ($rule['match_all_keys'] as $key) {
            if (!array_key_exists((string)$key, $payload)) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return true;
        }
    }

    if (isset($rule['match_any_key_regex']) && is_array($rule['match_any_key_regex'])) {
        foreach (array_keys($payload) as $payloadKey) {
            foreach ($rule['match_any_key_regex'] as $regex) {
                if (regex_key_match((string)$regex, (string)$payloadKey)) {
                    return true;
                }
            }
        }
    }

    if (isset($rule['match_any_regex']) && is_array($rule['match_any_regex'])) {
        foreach ($rule['match_any_regex'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = (string)($entry['key'] ?? '');
            $pattern = (string)($entry['pattern'] ?? '');
            if ($key !== '' && $pattern !== '' && isset($payload[$key]) && regex_key_match($pattern, (string)$payload[$key])) {
                return true;
            }
        }
    }

    return false;
}

function detect_unknown_raw_keys(array $payload, array $mapping): array
{
    $knownKeys = build_known_key_set($mapping);
    $knownPatterns = build_known_patterns($mapping);

    $unknown = [];
    foreach (array_keys($payload) as $key) {
        $key = (string)$key;
        if (isset($knownKeys[$key])) {
            continue;
        }

        if (matches_any_pattern($key, $knownPatterns)) {
            continue;
        }

        $unknown[] = $key;
    }

    sort($unknown, SORT_NATURAL | SORT_FLAG_CASE);
    return $unknown;
}

function build_known_key_set(array $mapping): array
{
    $keys = [];

    foreach (($mapping['known_raw_keys'] ?? []) as $key) {
        $keys[(string)$key] = true;
    }

    foreach (($mapping['profile']['metadata_fields'] ?? []) as $definition) {
        if (is_array($definition) && isset($definition['source'])) {
            $keys[(string)$definition['source']] = true;
        }
    }

    foreach (($mapping['fields'] ?? []) as $definition) {
        if (!is_array($definition)) {
            continue;
        }
        foreach (($definition['sources'] ?? []) as $source) {
            if (is_array($source) && isset($source['key'])) {
                $keys[(string)$source['key']] = true;
            }
        }
    }

    foreach (($mapping['dynamic_sensors'] ?? []) as $family) {
        if (!is_array($family)) {
            continue;
        }
        foreach (($family['fields'] ?? []) as $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }
            foreach (($fieldDefinition['sources'] ?? []) as $source) {
                if (is_array($source) && isset($source['key'])) {
                    $keys[(string)$source['key']] = true;
                }
            }
        }
    }

    return $keys;
}

function build_known_patterns(array $mapping): array
{
    $patterns = [];

    foreach (($mapping['known_key_patterns'] ?? []) as $pattern) {
        $patterns[] = (string)$pattern;
    }

    foreach (($mapping['dynamic_sensors'] ?? []) as $family) {
        if (!is_array($family)) {
            continue;
        }
        foreach (($family['fields'] ?? []) as $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }
            foreach (($fieldDefinition['sources'] ?? []) as $source) {
                if (is_array($source) && isset($source['key_pattern'])) {
                    $patterns[] = key_pattern_to_regex((string)$source['key_pattern']);
                }
            }
        }
    }

    return array_values(array_unique($patterns));
}

function key_pattern_to_regex(string $pattern): string
{
    $quoted = preg_quote($pattern, '~');
    $quoted = str_replace('\\{channel\\}', '[0-9]+', $quoted);
    return '^' . $quoted . '$';
}

function matches_any_pattern(string $key, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (regex_key_match((string)$pattern, $key)) {
            return true;
        }
    }

    return false;
}

function regex_key_match(string $pattern, string $value): bool
{
    if ($pattern === '') {
        return false;
    }

    $delimiter = '~';
    $regex = $delimiter . str_replace($delimiter, '\\' . $delimiter, $pattern) . $delimiter . 'i';
    return @preg_match($regex, $value) === 1;
}

function sanitize_payload(array $payload, bool $redactPasskey): array
{
    if ($redactPasskey && array_key_exists('PASSKEY', $payload)) {
        $payload['PASSKEY'] = '[redacted]';
    }

    return $payload;
}

function write_json_atomic(string $path, array $data): void
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (!is_string($json)) {
        throw new \RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
    }

    $tmpPath = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException('Unable to write temporary file: ' . $tmpPath);
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new \RuntimeException('Unable to replace file: ' . $path);
    }
}

function append_line_atomic(string $path, string|false $line): void
{
    if (!is_string($line)) {
        throw new \RuntimeException('Unable to encode log line');
    }

    if (file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new \RuntimeException('Unable to append log file: ' . $path);
    }
}

function action_log(string $logDir, string $action, bool $enabled = true): void
{
    if (!$enabled) {
        return;
    }

    $line = date('Y-m-d H:i:s') . ' - ' . $action;
    @file_put_contents(
        rtrim($logDir, '/') . '/' . gmdate('Y-m-d') . '.log',
        $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function run_realtime_if_enabled(array $config, string $baseDir, string $logDir, bool $writeActionLog): void
{
    if (!cfg_bool($config, 'realtime', 'enabled', false)) {
        return;
    }

    $mode = strtolower((string)cfg($config, 'realtime', 'mode', 'none'));

    if ($mode === 'none' || $mode === '') {
        return;
    }

    if ($mode === 'require') {
        $script = resolve_path((string)cfg($config, 'realtime', 'script', 'generate-realtime.php'), $baseDir);
        if (!is_file($script)) {
            action_log($logDir, 'Ecowitt-Upload V2 - Realtime script not found: ' . $script, $writeActionLog);
            return;
        }

        ob_start();
        try {
            require $script;
            ob_end_clean();
            action_log($logDir, 'Ecowitt-Upload V2 - Realtime generation done with require mode', $writeActionLog);
        } catch (\Throwable $e) {
            ob_end_clean();
            action_log($logDir, 'Ecowitt-Upload V2 - Realtime require error: ' . $e->getMessage(), $writeActionLog);
        }
        return;
    }

    if ($mode === 'curl') {
        $url = (string)cfg($config, 'realtime', 'url', '');
        if ($url === '') {
            action_log($logDir, 'Ecowitt-Upload V2 - Realtime curl URL is empty', $writeActionLog);
            return;
        }

        $timeout = (int)cfg($config, 'realtime', 'curl_timeout', 10);
        $ok = http_ping($url, $timeout);
        action_log(
            $logDir,
            'Ecowitt-Upload V2 - Realtime curl mode ' . ($ok ? 'OK' : 'FAILED') . ': ' . $url,
            $writeActionLog
        );
        return;
    }

    action_log($logDir, 'Ecowitt-Upload V2 - Unknown realtime mode: ' . $mode, $writeActionLog);
}

function http_ping(string $url, int $timeout): bool
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode >= 200 && $httpCode < 500;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => max(1, $timeout),
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    return $response !== false;
}

function respond(string $message, int $status): never
{
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
    }

    echo $message . PHP_EOL;
    exit($status >= 400 ? 1 : 0);
}
