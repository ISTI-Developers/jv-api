<?php

const ACCOUNT_TITLES_SOURCE_URL = 'https://api.unmg.com.ph/jv/expenses/category';
const REVENUE_ACCOUNT_TITLES_SOURCE_URL = 'https://api.unmg.com.ph/jv/revenue/category';

function fetchExternalAccountTitles(string $sourceUrl = ACCOUNT_TITLES_SOURCE_URL): array
{
    $response = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($sourceUrl);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException($error ?: 'External account titles source returned an invalid response');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents($sourceUrl, false, $context);

        if ($response === false) {
            throw new RuntimeException('Unable to fetch external account titles source');
        }
    }

    $payload = json_decode($response, true);

    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        throw new RuntimeException('External account titles source returned invalid JSON');
    }

    $accountTitles = [];

    foreach ($payload['data'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $accountNo = trim((string) ($item['cAcctNo'] ?? ''));
        $accountTitle = trim((string) ($item['cTitle'] ?? ''));

        if ($accountNo === '' || $accountTitle === '') {
            continue;
        }

        $accountTitles[$accountNo] = [
            'account_no' => $accountNo,
            'account_title' => $accountTitle,
        ];
    }

    return array_values($accountTitles);
}
