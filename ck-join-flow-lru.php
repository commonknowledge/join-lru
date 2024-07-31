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
use CommonKnowledge\JoinBlock\Services\JoinService;
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
        Field::make('text', 'airtable_gocardless_status_column'),
    ];
    return array_merge($fields, $extra_fields);
});

add_filter('ck_join_flow_pre_webhook_post', function ($request) {
    // Prepend email to the session token to fix issue of users reusing
    // the same tab with different details
    $body = json_decode($request["body"], true);
    $body["sessionToken"] = $body["email"] . ':' . $body["sessionToken"];
    $body["phoneNumber"] = str_replace("+44", "0", $body["phoneNumber"]);
    $request["body"] = json_encode($body);
    return $request;
});

add_action('ck_join_flow_delete_existing_gocardless_customer', function ($email, $customerId, $mandateId) {
    if (!class_exists('CommonKnowledge\\JoinBlock\\Settings')) {
        error_log('The ck-join-flow-lru plugin requires the ck-join-flow plugin to be installed and activated.');
        return null;
    }

    deleteExistingGoCardlessCustomer($email, $customerId, $mandateId);
}, 10, 3);

add_action('rest_api_init', function () {
    register_rest_route('join/v1', '/gocardless/webhook', array(
        'methods' => ['GET', 'POST'],
        'permission_callback' => function ($req) {
            return true;
        },
        'callback' => function (WP_REST_Request $request) {
            global $joinBlockLog;

            $joinBlockLog->info("Received GoCardless webhook: " . $request->get_body());
            $millisToSleep = rand(0, 10000);
            $joinBlockLog->info("GoCardless webhook: sleeping for $millisToSleep millis");
            usleep($millisToSleep * 1000);

            $json = json_decode($request->get_body(), true);
            $events = $json ? $json['events'] : [];
            foreach ($events as $event) {
                $resourceType = $event['resource_type'];
                $action = $event['action'];
                if ($resourceType === "payments" && $action === "confirmed") {
                    $paymentId = $event['links']['payment'];
                    $customerId = GocardlessService::getCustomerIdByPayment($paymentId);
                    if (!$customerId) {
                        $joinBlockLog->error("Could not find member for payment $paymentId");
                    } else {
                        recordMemberIsActive($customerId);
                    }
                }
            }

            ensureSubscriptionsCreated();
        }
    ));
});

function ensureSubscriptionsCreated() {
    global $wpdb;
    global $joinBlockLog;

    $joinBlockLog->info("Running ensureSubscriptionsCreated");

    $sql = "SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE 'JOIN_FORM_UNPROCESSED_GOCARDLESS_REQUEST_%'";
    $results = $wpdb->get_results($sql);
    foreach ($results as $result) {
        $joinBlockLog->error("ensureSubscriptionsCreated: processing {$result->option_name}: {$result->option_value}");
        try {
            $data = json_decode($result->option_value, true);
            $createdAt = $data['createdAt'] ?? 0;
            if ((time() - $createdAt) < 120) {
                $joinBlockLog->error("ensureSubscriptionsCreated: not processing {$result->option_name}: waiting at least 2 minutes.");
                continue;
            }

            $customer = GocardlessService::getCustomerIdByCompletedBillingRequest($data['gcBillingRequestId']);
            if (!$customer) {
                $joinBlockLog->error("ensureSubscriptionsCreated: could not process {$result->option_name}: user did not set up mandate.");
                // Try for one day
                $day = 24 * 60 * 60;

                $joinBlockLog->error("ensureSubscriptionsCreated: checking if should delete {$result->option_name}, created at {$createdAt}");

                if ((time() - $createdAt) > $day) {
                    $joinBlockLog->error("ensureSubscriptionsCreated: deleting unprocessable {$result->option_name}");
                    delete_option($result->option_name);
                } else {
                    $joinBlockLog->error("ensureSubscriptionsCreated: will retry {$result->option_name}");
                }
                continue;
            }

            JoinService::handleJoin($data);
            delete_option($result->option_name);
            $joinBlockLog->info("ensureSubscriptionsCreated: success, deleting option {$result->option_name}");
        } catch (\Exception $e) {
            $joinBlockLog->error("ensureSubscriptionsCreated: could not process {$result->option_value}: {$e->getMessage()}");
        }
    }
}

function deleteExistingGoCardlessCustomer($email, $customerId, $mandateId)
{
    global $joinBlockLog;

    $baseId = Settings::get('airtable_base_id');
    $tableId = Settings::get('airtable_table_id');
    $accessToken = Settings::get('airtable_access_token');
    if (!$baseId || !$tableId || !$accessToken) {
        $joinBlockLog->error('Missing AirTable Base ID, Table ID and/or Access Token');
        return null;
    }

    $joinBlockLog->info("Looking up existing GoCardless customer for email " . $email);

    $customerColumn = Settings::get('airtable_gocardless_customer_id_column') ?: 'GC customer ID';
    $mandateColumn = Settings::get('airtable_gocardless_customer_id_column') ?: 'GC mandate ID';

    $filterFormula = urlencode('{Email address} = "' . $email . '"');

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
        // Remove previous customer, so a new one can be created
        $existingCustomerId = $record['fields'][$customerColumn] ?? '';
        $existingMandateId = $record['fields'][$mandateColumn] ?? '';
        if (!$existingCustomerId) {
            $joinBlockLog->info("Not removing existing GoCardless customer for email " . $email . ": previous customer ID not found");
            continue;
        }
        if (!$customerId) {
            $joinBlockLog->info("Not removing existing GoCardless mandate for email " . $email . ": new customer not yet created");
            continue;
        }
        if ($existingCustomerId !== $customerId) {
            $joinBlockLog->info("Removing existing GoCardless customer and mandates for email " . $email . ": new customer was created");
            GocardlessService::removeCustomerMandates($existingCustomerId);
            GocardlessService::removeCustomerById($existingCustomerId);
            continue;
        }
        if (!$existingMandateId) {
            $joinBlockLog->info("Not removing existing GoCardless mandate for email " . $email . ": previous mandate ID not found");
            continue;
        }
        if (!$mandateId) {
            $joinBlockLog->info("Not removing existing GoCardless mandate for email " . $email . ": new mandate not yet created");
            continue;
        }
        if ($existingMandateId !== $mandateId) {
            $joinBlockLog->info("Removing existing GoCardless mandates for email " . $email . ": new mandate was created");
            GocardlessService::removeCustomerMandates($existingCustomerId);
            continue;
        }
        $details = json_encode([
            "existingCustomerId" => $existingCustomerId,
            "existingMandateId" => $existingMandateId,
            "newCustomerId" => $customerId,
            "newMandateId" => $mandateId
        ]);
        $joinBlockLog->info("Not removing existing GoCardless mandates for email " . $email . ": unclear what to do. Details: $details");
    }
}

function recordMemberIsActive($gcCustomerId)
{
    global $joinBlockLog;

    $baseId = Settings::get('airtable_base_id');
    $tableId = Settings::get('airtable_table_id');
    $accessToken = Settings::get('airtable_access_token');
    if (!$baseId || !$tableId || !$accessToken) {
        $joinBlockLog->error('Missing AirTable Base ID, Table ID and/or Access Token');
        return null;
    }

    $customerColumn = Settings::get('airtable_gocardless_customer_id_column') ?: 'GC customer ID';
    $statusColumn = Settings::get('airtable_gocardless_status_column') ?: 'GC mandate status';

    $url = "https://api.airtable.com/v0/$baseId/$tableId";

    $filterFormula = urlencode('{' . $customerColumn . '} = "' . $gcCustomerId . '"');

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

    if (empty($records)) {
        $joinBlockLog->error('Payment received, but could not find member with GC customer code ' . $gcCustomerId);
        return;
    }

    foreach ($records as $record) {
        $url = "https://api.airtable.com/v0/$baseId/$tableId/{$record['id']}";
        $body =  [
            "fields" => [
                $customerColumn => $gcCustomerId,
                $statusColumn => "active"
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
            "Content-type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        try {
            $response = curl_exec($ch);
            if (!$response) {
                $error = curl_error($ch);
                $joinBlockLog->error('AirTable request did not return an ok response: ' . $error);
                return null;
            }
            $gcData = json_decode($response, true);
            if (empty($gcData['id'])) {
                $joinBlockLog->error(
                    'Could not update AirTable record with id ' . $record['id'] . ': ' . $response
                );
            }
        } catch (\Exception $e) {
            $joinBlockLog->error('AirTable request failed: ' . $e->getMessage());
        }
    }
}
