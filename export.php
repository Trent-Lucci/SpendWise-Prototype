<?php
require_once 'db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=spendwise_transactions_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');

// Column headers
fputcsv($output, ['ID', 'Date', 'Description', 'Type', 'Category', 'Amount']);

$query = $pdo->query("
    SELECT t.id, t.transaction_date, t.title, t.type, COALESCE(c.name, 'Uncategorized') AS category, t.amount
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    ORDER BY t.transaction_date DESC, t.id DESC
");

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['transaction_date'],
        $row['title'],
        ucfirst($row['type']),
        $row['category'],
        $row['amount']
    ]);
}

fclose($output);
exit;