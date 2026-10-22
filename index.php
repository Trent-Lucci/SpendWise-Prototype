<?php
require_once 'db.php';

$alertMessage = '';
$alertType = '';

// Helper: Normalize date formats to MySQL YYYY-MM-DD
function parseDateToMySQL($dateStr) {
    $dateStr = trim($dateStr);
    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $dateStr);
        if ($d && $d->format($fmt) === $dateStr) {
            return $d->format('Y-m-d');
        }
    }
    $ts = strtotime($dateStr);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

// 1. Handle Single Transaction Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title']);
    $amount = floatval($_POST['amount']);
    $type = $_POST['type'];
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $date = $_POST['transaction_date'];

    if ($title && $amount > 0 && $date) {
        $stmt = $pdo->prepare("INSERT INTO transactions (title, amount, type, category_id, transaction_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $amount, $type, $category_id, $date]);
    }
    header('Location: index.php');
    exit;
}

// 2. Handle Budget Limit Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_limit') {
    $cat_id = intval($_POST['cat_id']);
    $limit = floatval($_POST['monthly_limit']);
    $stmt = $pdo->prepare("UPDATE categories SET monthly_limit = ? WHERE id = ?");
    $stmt->execute([$limit, $cat_id]);
    header('Location: index.php');
    exit;
}

// 3. Header-Aware CSV Import Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $filePath = $_FILES['csv_file']['tmp_name'];
        
        if (($handle = fopen($filePath, 'r')) !== FALSE) {
            // Strip potential UTF-8 BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Read header row and normalize column titles to lowercase
            $rawHeader = fgetcsv($handle);
            if (!$rawHeader) {
                header("Location: index.php?msg=err_upload");
                exit;
            }
            $header = array_map(fn($h) => strtolower(trim($h)), $rawHeader);

            // Dynamically locate column indexes
            $dateIdx = array_search('date', $header);
            $titleIdx = array_search('description', $header);
            if ($titleIdx === false) $titleIdx = array_search('title', $header);
            if ($titleIdx === false) $titleIdx = array_search('name', $header);

            $typeIdx = array_search('type', $header);
            if ($typeIdx === false) $typeIdx = array_search('drcr', $header);

            $catIdx = array_search('category', $header);
            $amountIdx = array_search('amount', $header);

            // Fallback to standard 0,1,2,3,4 if headers are missing
            if ($dateIdx === false) $dateIdx = 0;
            if ($titleIdx === false) $titleIdx = 1;
            if ($typeIdx === false) $typeIdx = 2;
            if ($catIdx === false) $catIdx = 3;
            if ($amountIdx === false) $amountIdx = 4;

            // Build dynamic Category lookup map
            $catMap = [];
            $catStmt = $pdo->query("SELECT id, LOWER(TRIM(name)) AS cat_name FROM categories");
            while ($c = $catStmt->fetch()) {
                $catMap[$c['cat_name']] = $c['id'];
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO transactions (title, amount, type, category_id, transaction_date) 
                VALUES (?, ?, ?, ?, ?)
            ");

            $insertedCount = 0;

            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if (empty($row) || count(array_filter($row)) === 0) continue;

                $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
                $rawTitle = isset($row[$titleIdx]) ? trim($row[$titleIdx]) : '';
                $rawType = isset($row[$typeIdx]) ? strtolower(trim($row[$typeIdx])) : '';
                $rawCat = isset($row[$catIdx]) ? strtolower(trim($row[$catIdx])) : '';
                $rawAmount = isset($row[$amountIdx]) ? trim($row[$amountIdx]) : '0';

                $cleanAmount = abs(floatval(str_replace(['$', ',', ' '], '', $rawAmount)));
                if ($cleanAmount <= 0 || empty($rawTitle)) continue;

                // Normalize Type
                $type = in_array($rawType, ['income', 'credit', 'cr']) ? 'income' : 'expense';
                $categoryId = $catMap[$rawCat] ?? null;
                $parsedDate = parseDateToMySQL($rawDate);

                $insertStmt->execute([$rawTitle, $cleanAmount, $type, $categoryId, $parsedDate]);
                $insertedCount++;
            }
            fclose($handle);

            header("Location: index.php?msg=imported&count={$insertedCount}");
            exit;
        }
    }
}

// 4. Handle Delete Transaction
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php');
    exit;
}

// Handle Delete All Transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_all') {
    $pdo->query("TRUNCATE TABLE transactions");
    header('Location: index.php?msg=cleared');
    exit;
}

// Status messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'imported') {
        $count = intval($_GET['count'] ?? 0);
        $alertMessage = "Successfully imported {$count} transaction(s)!";
        $alertType = 'success';
    } elseif ($_GET['msg'] === 'cleared') {
        $alertMessage = "All transactions have been deleted.";
        $alertType = 'error';
    } elseif ($_GET['msg'] === 'err_upload') {
        $alertMessage = "Upload error: Could not process the file.";
        $alertType = 'error';
    }
}

// 5. Data Queries for Dashboard
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$transactions = $pdo->query("
    SELECT t.*, c.name AS category_name 
    FROM transactions t 
    LEFT JOIN categories c ON t.category_id = c.id 
    ORDER BY t.transaction_date DESC, t.id DESC
")->fetchAll();

$totals = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
    FROM transactions
")->fetch();

$totalIncome = floatval($totals['total_income']);
$totalExpense = floatval($totals['total_expense']);
$netBalance = $totalIncome - $totalExpense;

// Monthly Budgets
$currentYear = date('Y');
$currentMonth = date('m');
$stmtBudget = $pdo->prepare("
    SELECT 
        c.id, 
        c.name, 
        c.monthly_limit,
        COALESCE(SUM(t.amount), 0) AS current_spent
    FROM categories c
    LEFT JOIN transactions t ON c.id = t.category_id 
        AND t.type = 'expense' 
        AND YEAR(t.transaction_date) = ?
        AND MONTH(t.transaction_date) = ?
    WHERE c.type = 'expense'
    GROUP BY c.id, c.name, c.monthly_limit
    ORDER BY c.name ASC
");
$stmtBudget->execute([$currentYear, $currentMonth]);
$budgetStats = $stmtBudget->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpendWise - Budget & Expense Tracker</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 2rem; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; font-size: 1.75rem; }
        
        .alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-weight: 500; font-size: 0.9rem; }
        .alert.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
        .card { padding: 1.25rem; border-radius: 8px; border: 1px solid #e2e8f0; }
        .card.income { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .card.expense { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .card.balance { background: #f8fafc; border-color: #cbd5e1; }
        .card small { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; }
        .card h2 { margin: 0.5rem 0 0; font-size: 1.5rem; }
        
        /* Action Bar for CSV Import/Export */
        .action-bar { display: flex; justify-content: space-between; align-items: center; background: #f1f5f9; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 12px; }
        .import-box { display: flex; align-items: center; gap: 8px; }
        .btn-upload { background: #0284c7; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; }
        .btn-upload:hover { background: #0369a1; }
        .btn-export { background: #16a34a; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        .btn-export:hover { background: #15803d; }

        form.tx-form { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 2rem; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 8px; align-items: center; }
        input, select, button { padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; }
        .tx-form input[name="title"] { flex: 2; min-width: 150px; }
        .tx-form input[name="amount"] { flex: 1; min-width: 90px; }
        .tx-form select { flex: 1.2; min-width: 130px; }
        .tx-form input[type="date"] { flex: 1.2; min-width: 130px; }
        .btn-submit { background: #2563eb; color: #fff; border: none; cursor: pointer; font-weight: 600; padding: 0.65rem 1.4rem; border-radius: 6px; }
        .btn-submit:hover { background: #1d4ed8; }

        /* Budget Bars */
        .budget-section { margin-bottom: 2rem; }
        .budget-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
        .budget-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; background: #fff; }
        .budget-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.5rem; font-size: 0.9rem; }
        .progress-bar-bg { width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-bottom: 0.5rem; }
        .progress-bar-fill { height: 100%; border-radius: 999px; }
        .budget-footer { display: flex; justify-content: space-between; font-size: 0.75rem; color: #64748b; }
        .limit-form { display: flex; gap: 4px; margin-top: 0.5rem; }
        .limit-form input { padding: 2px 6px; font-size: 0.75rem; width: 70px; }
        .limit-form button { padding: 2px 6px; font-size: 0.75rem; background: #e2e8f0; border: none; cursor: pointer; border-radius: 4px; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 0.75rem; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-size: 0.85rem; color: #64748b; }
        .amount-income { color: #16a34a; font-weight: 600; }
        .amount-expense { color: #dc2626; font-weight: 600; }
        .btn-delete { color: #ef4444; text-decoration: none; font-size: 0.85rem; border: 1px solid #fca5a5; padding: 0.25rem 0.5rem; border-radius: 4px; }
        .btn-delete:hover { background: #fee2e2; }
    </style>
</head>
<body>

<div class="container">
    <h1>SpendWise Dashboard</h1>

    <?php if ($alertMessage): ?>
        <div class="alert <?= $alertType ?>"><?= htmlspecialchars($alertMessage) ?></div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="cards">
        <div class="card income">
            <small>Total Income</small>
            <h2>+$<?= number_format($totalIncome, 2) ?></h2>
        </div>
        <div class="card expense">
            <small>Total Expenses</small>
            <h2>-$<?= number_format($totalExpense, 2) ?></h2>
        </div>
        <div class="card balance">
            <small>Net Balance</small>
            <h2 style="color: <?= $netBalance >= 0 ? '#0f172a' : '#dc2626' ?>;">
                $<?= number_format($netBalance, 2) ?>
            </h2>
        </div>
    </div>

    <!-- Monthly Expense Budgets -->
    <div class="budget-section">
        <h3 style="margin-bottom: 0.75rem;">Monthly Expense Budgets (<?= date('F Y') ?>)</h3>
        <div class="budget-grid">
            <?php foreach ($budgetStats as $b): 
                $spent = floatval($b['current_spent']);
                $limit = floatval($b['monthly_limit']);
                $pct = $limit > 0 ? min(100, round(($spent / $limit) * 100)) : 0;
                
                $fillColor = '#16a34a';
                if ($pct >= 75 && $pct < 100) $fillColor = '#f59e0b';
                if ($spent >= $limit && $limit > 0) $fillColor = '#dc2626';
            ?>
                <div class="budget-card">
                    <div class="budget-header">
                        <strong><?= htmlspecialchars($b['name']) ?></strong>
                        <span style="font-weight: 600; color: <?= $spent > $limit && $limit > 0 ? '#dc2626' : '#1e293b' ?>;">
                            $<?= number_format($spent, 2) ?> / $<?= number_format($limit, 2) ?>
                        </span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= $pct ?>%; background-color: <?= $fillColor ?>;"></div>
                    </div>
                    <div class="budget-footer">
                        <span><?= $pct ?>% used</span>
                        <span><?= ($limit - $spent >= 0) ? '$' . number_format($limit - $spent, 2) . ' remaining' : 'Over by $' . number_format(abs($limit - $spent), 2) ?></span>
                    </div>
                    <form method="POST" action="index.php" class="limit-form">
                        <input type="hidden" name="action" value="update_limit">
                        <input type="hidden" name="cat_id" value="<?= $b['id'] ?>">
                        <input type="number" step="0.01" name="monthly_limit" placeholder="Limit" value="<?= $limit ?>">
                        <button type="submit">Set</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CSV Import / Export Actions -->
    <div class="action-bar">
        <form method="POST" action="index.php" enctype="multipart/form-data" class="import-box" style="margin: 0;">
            <input type="hidden" name="action" value="import_csv">
            <span style="font-size: 0.85rem; font-weight: 600;">Import CSV:</span>
            <input type="file" name="csv_file" accept=".csv" required style="padding: 2px;">
            <button type="submit" class="btn-upload">Upload</button>
        </form>

        <a href="export.php" class="btn-export">📥 Export CSV</a>
    </div>

    <!-- Manual Entry Form -->
    <form method="POST" action="index.php" class="tx-form">
        <input type="hidden" name="action" value="add">
        <input type="text" name="title" placeholder="Description (e.g., Lunch)" required>
        <input type="number" step="0.01" name="amount" placeholder="Amount" required>
        
        <select name="type" id="txType" required>
            <option value="expense">Expense</option>
            <option value="income">Income</option>
        </select>

        <select name="category_id" id="txCategory" required>
            <option value="">Select Category</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" data-type="<?= $cat['type'] ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
        <button type="submit" class="btn-submit">Add</button>
    </form>

    <!-- Transactions Table -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; margin-bottom: 0.75rem;">
    <h3 style="margin: 0;">Transaction History</h3>
    <?php if (!empty($transactions)): ?>
        <form method="POST" action="index.php" style="margin: 0; padding: 0; background: none; border: none;" onsubmit="return confirm('⚠️ Are you sure you want to delete ALL transactions? This cannot be undone.');">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" style="background: #dc2626; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer;">
                🗑️ Clear All
            </button>
        </form>
    <?php endif; ?>
</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Category</th>
                <th>Amount</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 2rem;">No transactions recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['transaction_date']) ?></td>
                        <td><?= htmlspecialchars($t['title']) ?></td>
                        <td><?= htmlspecialchars($t['category_name'] ?? 'Uncategorized') ?></td>
                        <td class="<?= $t['type'] === 'income' ? 'amount-income' : 'amount-expense' ?>">
                            <?= $t['type'] === 'income' ? '+' : '-' ?>$<?= number_format($t['amount'], 2) ?>
                        </td>
                        <td>
                            <a href="index.php?delete=<?= $t['id'] ?>" class="btn-delete" onclick="return confirm('Delete this record?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Dynamic Category Filtering
const txType = document.getElementById('txType');
const txCategory = document.getElementById('txCategory');
const allCatOptions = Array.from(txCategory.querySelectorAll('option')).filter(opt => opt.value !== "");

function updateCategoryOptions() {
    const selectedType = txType.value;
    txCategory.innerHTML = '<option value="">Select Category</option>';
    allCatOptions.forEach(opt => {
        if (opt.dataset.type === selectedType) {
            txCategory.appendChild(opt.cloneNode(true));
        }
    });
}

txType.addEventListener('change', updateCategoryOptions);
document.addEventListener('DOMContentLoaded', updateCategoryOptions);
</script>

</body>
</html>