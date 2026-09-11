<?php
function ciFailureSummary(string $exception): array
{
    $result = ['category' => 'unclassified'];
    foreach (
        [
            'Provisioning-Zugang nicht eingerichtet.' => 'credential_file_not_readable',
            'Tenant migration failed' => 'migration_failed',
            'Invalid identifier' => 'invalid_identifier',
        ]
        as $message => $category
    ) {
        if (str_contains($exception, $message)) {
            $result['category'] = $category;
        }
    }
    if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $exception, $match)) {
        $result['category'] = 'database';
        if (
            in_array(
                $match[1],
                ['HY000', '42000', '28000', '42S02', '42S01', '23000', '08006', 'IM001'],
                true,
            )
        ) {
            $result['sqlstate'] = $match[1];
        }
    }
    if (
        preg_match(
            '/SQLSTATE\[[A-Z0-9]{5}\](?:\s*\[(\d{4})\]|:[^:\r\n]{1,80}:\s*(\d{4})\b)/',
            $exception,
            $match,
        )
    ) {
        $code = (int) ($match[1] ?: $match[2] ?? 0);
        if (
            in_array(
                $code,
                [1044, 1045, 1049, 1064, 1142, 1143, 1227, 1396, 1410, 1824, 2002, 2006, 2013],
                true,
            )
        ) {
            $result['driver_code'] = $code;
        }
    }
    if (preg_match('/[\\\\\/]ProvisionTenant\.php(?::|\()(\d{1,4})\b/', $exception, $match)) {
        $result['provision_line'] = (int) $match[1];
    }
    return $result;
}
