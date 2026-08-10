<?php
$baseUrl = 'http://localhost:8000';

echo "1. Simulating ticket creation from Agent Dashboard via POST request...\n";
$postData = json_encode([
    'customer_name' => 'Test Alignment Verify',
    'customer_phone' => '999999999',
    'order_id' => 'ORD-TEST-123',
    'issue_type' => 'Wrong item',
    'description' => 'Automated integration verification test',
    'delivery_date' => date('Y-m-d'),
    'department' => 'Warehouse',
    'agent_id' => '1003',
    'priority' => 'High'
]);

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => $postData,
        'timeout' => 5
    ]
];
$context  = stream_context_create($options);
$response = file_get_contents($baseUrl . '/app/agent_dashboard/index.php?action=save_case', false, $context);

if ($response === false) {
    echo "❌ Failed to POST new case to Agent Dashboard.\n";
    exit(1);
}

echo "Response from Agent Dashboard: " . $response . "\n";

echo "\n2. Fetching tickets from Department Ticket Portal...\n";
$getTicketsUrl = $baseUrl . '/app/department_tickets/index.php?action=get_tickets&department=Warehouse&status=All&priority=All';
$responseGet = file_get_contents($getTicketsUrl);

if ($responseGet === false) {
    echo "❌ Failed to get tickets from Department Tickets Portal.\n";
    exit(1);
}

$data = json_decode($responseGet, true);
if (!$data || !isset($data['records'])) {
    echo "❌ Invalid JSON response from Department Tickets Portal.\n";
    exit(1);
}

$found = false;
foreach ($data['records'] as $r) {
    if ($r['customer_name'] === 'Test Alignment Verify' && $r['customer_phone'] === '999999999') {
        $found = true;
        echo "✅ SUCCESS: Ticket found in Department Ticket Portal!\n";
        echo "   Ticket Details: ID #{$r['case_id']} | Dept: {$r['department']} | Priority: {$r['priority']} | Status: {$r['status']}\n";
        break;
    }
}

if (!$found) {
    echo "❌ FAILED: Ticket was not found in the Department Ticket Portal database.\n";
    exit(1);
}
?>
