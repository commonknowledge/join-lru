<?php

/**
 * Plugin Name:     Common Knowledge Join Plugin LRU Extras
 * Description:     Common Knowledge join flow plugin LRU extras.
 * Version:         1.0.0
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     ck
 */

use Carbon_Fields\Field;
use CommonKnowledge\JoinBlock\Services\GocardlessService;
use CommonKnowledge\JoinBlock\Settings;

/**
 * This extension to the CK Join Flow plugin is used to handle updates
 * to existing GoCardless customers.
 */
add_filter('ck_join_flow_settings_fields', function ($fields) {
    $extra_fields = [
        Field::make('separator', 'airtable', 'AirTable'),
        Field::make('text', 'airtable_base_id'),
        Field::make('text', 'airtable_table_id'),
        Field::make('text', 'airtable_access_token'),
        Field::make('text', 'airtable_gocardless_customer_id_column'),
    ];
    return array_merge($fields, $extra_fields);
});

add_action('ck_join_flow_delete_existing_gocardless_customer', function ($data) {
    if (!class_exists('CommonKnowledge\\JoinBlock\\Settings')) {
        error_log('The ck-join-flow-lru plugin requires the ck-join-flow plugin to be installed and activated.');
        return null;
    }

    deleteExistingGoCardlessCustomer($data);
}, 10, 2);

function deleteExistingGoCardlessCustomer($data)
{
    global $joinBlockLog;

    $baseId = Settings::get('airtable_base_id');
    $tableId = Settings::get('airtable_table_id');
    $accessToken = Settings::get('airtable_access_token');
    if (!$baseId || !$tableId || !$accessToken) {
        $joinBlockLog->error('Missing AirTable Base ID, Table ID and/or Access Token');
        return null;
    }

    $email = $data['email'] ?? '';
    if (!$email) {
        return null;
    }

    $customerColumn = Settings::get('airtable_gocardless_subscription_id_column') ?: 'GC customer ID';

    $filterFormula = urlencode('{email} = "' . $email . '"');

    $url = "https://api.airtable.com/v0/$baseId/$tableId?filterByFormula=$filterFormula";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
    ]);
    $records = [];
    try {
        $response = curl_exec($ch);
        if (!$response) {
            $error = curl_error($ch);
            $joinBlockLog->error('AirTable request did not return an ok response: ' . $error);
            return null;
        }
        $gcData = json_decode($response, true);
        $records = $gcData["records"];
    } catch (\Exception $e) {
        $joinBlockLog->error('AirTable request failed: ' . $e->getMessage());
    }

    $isUpdate = $data['isUpdateFlow'] ?? false;
    if (empty($records) && $isUpdate) {
        $joinBlockLog->error('Update flow was used, but email did not match a user');
        throw new Exception("Could not find your details, please check your email address", 101);
    }

    foreach ($records as $record) {
        $customerId = $record['fields'][$customerColumn] ?? '';
        if ($customerId) {
            GocardlessService::removeCustomerMandates($customerId);
            if ($customerId !== $data['gcCustomerId']) {
                GocardlessService::removeCustomerById($customerId);
            }
        }
    }
}