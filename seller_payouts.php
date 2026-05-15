<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/seller_balance.php';

$pdo = bv_seller_balance_pdo();
$adminId = function_exists('bv_seller_balance_current_user_id') ? bv_seller_balance_current_user_id() : (int)($_SESSION['admin_id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt(mixed $amount, string $currency = 'USD'): string
{
    return h($currency) . ' ' . number_format((float)$amount, 2);
}

function table_exists(PDO $pdo, string $table): bool
{
    if (function_exists('_bv_sb_table_exists')) {
        return _bv_sb_table_exists($pdo, $table);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    if (function_exists('_bv_sb_column_exists')) {
        return _bv_sb_column_exists($pdo, $table, $column);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function seller_label(array $row): string
{
    foreach (['seller_name', 'display_name', 'name', 'username', 'email'] as $key) {
        if (!empty($row[$key])) {
            return (string)$row[$key];
        }
    }

    return 'Seller #' . (int)($row['seller_id'] ?? 0);
}

function status_badge_class(string $status): string
{
    return match (strtolower($status)) {
        'requested', 'pending' => 'badge badge-warning',
        'approved', 'processing' => 'badge badge-info',
        'paid', 'completed', 'success' => 'badge badge-success',
        'rejected', 'cancelled', 'failed' => 'badge badge-danger',
        default => 'badge badge-secondary',
    };
}

$keyword = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$sellerIdFilter = (int)($_GET['seller_id'] ?? 0);
$notice = trim((string)($_GET['notice'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

$hasPayouts = table_exists($pdo, 'seller_payout_requests');
$hasBalances = table_exists($pdo, 'seller_balances');
$hasLedger = table_exists($pdo, 'seller_ledger');
$hasUsers = table_exists($pdo, 'users');

$payoutRows = [];
$recentLedger = [];
$summary = [
    'pending_requests' => 0,
    'approved_requests' => 0,
    'paid_requests' => 0,
    'requested_amount' => 0.0,
    'approved_amount' => 0.0,
    'available_balance' => 0.0,
    'pending_balance' => 0.0,
    'held_balance' => 0.0,
    'paid_out_balance' => 0.0,
    'currency' => function_exists('bv_seller_balance_default_currency') ? bv_seller_balance_default_currency() : 'USD',
];

try {
    if ($hasPayouts) {
        $select = ['p.*'];
        $joins = '';
        $where = [];
        $params = [];

        if ($hasUsers) {
            $nameCols = [];
            foreach (['display_name', 'name', 'username', 'email'] as $col) {
                if (column_exists($pdo, 'users', $col)) {
                    $select[] = 'u.' . $col . ' AS ' . $col;
                    $nameCols[] = 'u.' . $col;
                }
            }
            $joins = ' LEFT JOIN users u ON u.id = p.seller_id';

            if ($keyword !== '' && $nameCols !== []) {
                $keywordParts = ['CAST(p.seller_id AS CHAR) LIKE :keyword'];
                if (column_exists($pdo, 'seller_payout_requests', 'id')) {
                    $keywordParts[] = 'CAST(p.id AS CHAR) LIKE :keyword';
                }
                foreach ($nameCols as $nameCol) {
                    $keywordParts[] = $nameCol . ' LIKE :keyword';
                }
                $where[] = '(' . implode(' OR ', $keywordParts) . ')';
                $params[':keyword'] = '%' . $keyword . '%';
            } elseif ($keyword !== '') {
                $where[] = '(CAST(p.seller_id AS CHAR) LIKE :keyword OR CAST(p.id AS CHAR) LIKE :keyword)';
                $params[':keyword'] = '%' . $keyword . '%';
            }
        } elseif ($keyword !== '') {
            $where[] = '(CAST(p.seller_id AS CHAR) LIKE :keyword OR CAST(p.id AS CHAR) LIKE :keyword)';
            $params[':keyword'] = '%' . $keyword . '%';
        }

        if ($sellerIdFilter > 0) {
            $where[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = $sellerIdFilter;
        }

        if ($statusFilter !== '') {
            $where[] = 'p.status = :status';
            $params[':status'] = $statusFilter;
        }

        $dateColumn = column_exists($pdo, 'seller_payout_requests', 'created_at') ? 'created_at' : (column_exists($pdo, 'seller_payout_requests', 'requested_at') ? 'requested_at' : 'id');
        if ($dateFrom !== '' && $dateColumn !== 'id') {
            $where[] = 'p.' . $dateColumn . ' >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '' && $dateColumn !== 'id') {
            $where[] = 'p.' . $dateColumn . ' <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM seller_payout_requests p' . $joins;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC LIMIT 100';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payoutRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($payoutRows as &$row) {
            $balance = function_exists('bv_seller_balance_get') ? bv_seller_balance_get((int)$row['seller_id']) : null;
            if (is_array($balance)) {
                $row['_balance'] = $balance;
                $summary['available_balance'] += (float)($balance['available_balance'] ?? 0);
                $summary['pending_balance'] += (float)($balance['pending_balance'] ?? 0);
                $summary['held_balance'] += (float)($balance['held_balance'] ?? 0);
                $summary['paid_out_balance'] += (float)($balance['paid_out_balance'] ?? 0);
                if (!empty($balance['currency'])) {
                    $summary['currency'] = (string)$balance['currency'];
                }
            }

            $status = strtolower((string)($row['status'] ?? ''));
            $amount = (float)($row['amount'] ?? 0);
            if (in_array($status, ['requested', 'pending'], true)) {
                $summary['pending_requests']++;
                $summary['requested_amount'] += $amount;
            } elseif ($status === 'approved') {
                $summary['approved_requests']++;
                $summary['approved_amount'] += $amount;
            } elseif ($status === 'paid') {
                $summary['paid_requests']++;
            }
            if (!empty($row['currency'])) {
                $summary['currency'] = (string)$row['currency'];
            }
        }
        unset($row);
    }

    if ($hasLedger) {
        $ledgerWhere = [];
        $ledgerParams = [];
        if ($sellerIdFilter > 0) {
            $ledgerWhere[] = 'seller_id = :ledger_seller_id';
            $ledgerParams[':ledger_seller_id'] = $sellerIdFilter;
        }
        $ledgerSql = 'SELECT * FROM seller_ledger';
        if ($ledgerWhere !== []) {
            $ledgerSql .= ' WHERE ' . implode(' AND ', $ledgerWhere);
        }
        $ledgerSql .= ' ORDER BY id DESC LIMIT 12';
        $ledgerStmt = $pdo->prepare($ledgerSql);
        $ledgerStmt->execute($ledgerParams);
        $recentLedger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'Unable to load seller payout data: ' . $e->getMessage();
}

$dashboardUrl = 'index.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Payouts</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card: #ffffff;
            --border: #dfe5ef;
            --muted: #667085;
            --text: #111827;
            --primary: #2563eb;
            --success: #087443;
            --warning: #a15c07;
            --danger: #b42318;
            --info: #175cd3;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: Arial, Helvetica, sans-serif; }
        a { color: var(--primary); text-decoration: none; }
        .page { max-width: 1360px; margin: 0 auto; padding: 24px; }
        .topbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .topbar h1 { margin: 0; font-size: 28px; line-height: 1.2; }
        .topbar p { margin: 6px 0 0; color: var(--muted); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px; padding: 8px 13px; border-radius: 10px; border: 1px solid var(--border); background: #fff; color: var(--text); font-size: 14px; font-weight: 700; cursor: pointer; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-success { background: var(--success); border-color: var(--success); color: #fff; }
        .btn-warning { background: #f79009; border-color: #f79009; color: #111827; }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn-sm { min-height: 32px; padding: 6px 10px; font-size: 12px; border-radius: 8px; }
        .cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04); }
        .card .label { color: var(--muted); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .card .value { margin-top: 10px; font-size: 26px; font-weight: 800; }
        .card .sub { margin-top: 6px; color: var(--muted); font-size: 13px; }
        .alert { margin-bottom: 16px; padding: 13px 15px; border-radius: 12px; border: 1px solid; line-height: 1.45; }
        .alert-warning { background: #fffaeb; border-color: #fedf89; color: #7a2e0e; }
        .alert-success { background: #ecfdf3; border-color: #abefc6; color: #054f31; }
        .alert-danger { background: #fef3f2; border-color: #fecdca; color: #7a271a; }
        .filters { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
        .field label { display: block; margin-bottom: 5px; color: var(--muted); font-size: 12px; font-weight: 700; }
        .field input, .field select, .field textarea { width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: var(--text); }
        .section-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .section-title h2 { margin: 0; font-size: 20px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1080px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; font-size: 14px; }
        th { color: var(--muted); background: #f8fafc; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .badge { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 800; white-space: nowrap; }
        .badge-warning { background: #fffaeb; color: var(--warning); }
        .badge-info { background: #eff8ff; color: var(--info); }
        .badge-success { background: #ecfdf3; color: var(--success); }
        .badge-danger { background: #fef3f2; color: var(--danger); }
        .badge-secondary { background: #f2f4f7; color: #344054; }
        .actions { display: flex; flex-wrap: wrap; gap: 7px; max-width: 480px; }
        .inline-form { display: inline-flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .inline-form input[type="text"], .inline-form input[type="number"] { width: 130px; min-height: 32px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 8px; }
        .muted { color: var(--muted); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px; }
        .adjust-form { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; align-items: end; }
        .empty { padding: 24px; color: var(--muted); text-align: center; }
        @media (max-width: 1100px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filters, .adjust-form, .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .page { padding: 14px; } .cards { grid-template-columns: 1fr; } .topbar { align-items: flex-start; } }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1>Seller Payouts</h1>
            <p>Review payout requests, release eligible pending funds, and manage seller balance adjustments.</p>
        </div>
        <a class="btn" href="<?= h($dashboardUrl) ?>">← Back to admin dashboard</a>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$hasPayouts || !$hasBalances): ?>
        <div class="alert alert-warning">
            Seller payout tables are not fully available. Actions are disabled until the seller balance migration has completed.
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            Use the buttons below only after verifying the seller, payout method, and ledger state. Balance figures are loaded from the seller balance helper and displayed as read-only context.
        </div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">
            <div class="label">Requested payouts</div>
            <div class="value"><?= (int)$summary['pending_requests'] ?></div>
            <div class="sub"><?= money_fmt($summary['requested_amount'], $summary['currency']) ?> awaiting review</div>
        </div>
        <div class="card">
            <div class="label">Approved payouts</div>
            <div class="value"><?= (int)$summary['approved_requests'] ?></div>
            <div class="sub"><?= money_fmt($summary['approved_amount'], $summary['currency']) ?> ready to mark paid</div>
        </div>
        <div class="card">
            <div class="label">Available balance</div>
            <div class="value"><?= money_fmt($summary['available_balance'], $summary['currency']) ?></div>
            <div class="sub">From bv_seller_balance_get()</div>
        </div>
        <div class="card">
            <div class="label">Held / pending</div>
            <div class="value"><?= money_fmt($summary['held_balance'] + $summary['pending_balance'], $summary['currency']) ?></div>
            <div class="sub">Held <?= money_fmt($summary['held_balance'], $summary['currency']) ?> · Pending <?= money_fmt($summary['pending_balance'], $summary['currency']) ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <form class="filters" method="get" action="seller_payouts.php">
            <div class="field">
                <label for="q">Seller keyword</label>
                <input id="q" type="search" name="q" value="<?= h($keyword) ?>" placeholder="Seller name, email, ID, payout ID">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['requested', 'approved', 'paid', 'rejected', 'cancelled', 'failed'] as $statusOption): ?>
                        <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= h(ucfirst($statusOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="date_from">From</label>
                <input id="date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>">
            </div>
            <div class="field">
                <label for="date_to">To</label>
                <input id="date_to" type="date" name="date_to" value="<?= h($dateTo) ?>">
            </div>
            <button class="btn btn-primary" type="submit">Filter</button>
        </form>
    </div>

    <div class="card">
        <div class="section-title">
            <h2>Payout requests</h2>
            <span class="muted"><?= count($payoutRows) ?> shown</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Seller</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Risk / hold</th>
                    <th>Method</th>
                    <th>Requested</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($payoutRows === []): ?>
                    <tr><td colspan="8" class="empty">No payout requests found.</td></tr>
                <?php endif; ?>
                <?php foreach ($payoutRows as $row): ?>
                    <?php
                    $payoutId = (int)($row['id'] ?? 0);
                    $sellerId = (int)($row['seller_id'] ?? 0);
                    $currency = (string)($row['currency'] ?? $summary['currency']);
                    $status = strtolower((string)($row['status'] ?? ''));
                    $balance = is_array($row['_balance'] ?? null) ? $row['_balance'] : [];
                    $held = (float)($balance['held_balance'] ?? 0);
                    $pending = (float)($balance['pending_balance'] ?? 0);
                    $riskBadge = $held > 0 ? 'Funds held' : ($pending > 0 ? 'Pending clearance' : 'No active hold');
                    $riskClass = $held > 0 ? 'badge-warning' : ($pending > 0 ? 'badge-info' : 'badge-success');
                    $method = (string)($row['payout_method'] ?? $row['method'] ?? '');
                    $created = (string)($row['created_at'] ?? $row['requested_at'] ?? '');
                    ?>
                    <tr>
                        <td>#<?= $payoutId ?></td>
                        <td>
                            <strong><?= h(seller_label($row)) ?></strong><br>
                            <span class="muted">Seller #<?= $sellerId ?></span><br>
                            <span class="muted">Avail <?= money_fmt($balance['available_balance'] ?? 0, $currency) ?></span>
                        </td>
                        <td><strong><?= money_fmt($row['amount'] ?? 0, $currency) ?></strong></td>
                        <td><span class="<?= h(status_badge_class($status)) ?>"><?= h($status !== '' ? ucfirst($status) : 'Unknown') ?></span></td>
                        <td>
                            <span class="badge <?= h($riskClass) ?>"><?= h($riskBadge) ?></span><br>
                            <span class="muted">Held <?= money_fmt($held, $currency) ?> · Pending <?= money_fmt($pending, $currency) ?></span>
                        </td>
                        <td><?= h($method !== '' ? $method : '—') ?></td>
                        <td><?= h($created !== '' ? $created : '—') ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($status === 'requested' || $status === 'pending'): ?>
                                    <form class="inline-form" method="post" action="seller_payout_action.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="payout_id" value="<?= $payoutId ?>">
                                        <input type="text" name="admin_note" placeholder="Approval note">
                                        <button class="btn btn-success btn-sm" type="submit">Approve</button>
                                    </form>
                                    <form class="inline-form" method="post" action="seller_payout_action.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="payout_id" value="<?= $payoutId ?>">
                                        <input type="text" name="admin_note" placeholder="Reject reason">
                                        <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status === 'approved'): ?>
                                    <form class="inline-form" method="post" action="seller_payout_action.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="payout_id" value="<?= $payoutId ?>">
                                        <input type="text" name="payment_reference" placeholder="Payment ref">
                                        <input type="text" name="payment_method" value="bank_transfer" placeholder="Method">
                                        <button class="btn btn-primary btn-sm" type="submit">Mark paid</button>
                                    </form>
                                    <form class="inline-form" method="post" action="seller_payout_action.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="payout_id" value="<?= $payoutId ?>">
                                        <input type="text" name="admin_note" placeholder="Cancel reason">
                                        <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                    </form>
                                <?php endif; ?>
                                <form class="inline-form" method="post" action="seller_payout_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="release_pending">
                                    <input type="hidden" name="seller_id" value="<?= $sellerId ?>">
                                    <button class="btn btn-warning btn-sm" type="submit">Release pending</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="section-title">
                <h2>Adjust seller balance</h2>
            </div>
            <form class="adjust-form" method="post" action="seller_payout_action.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="adjust_balance">
                <div class="field">
                    <label for="adjust_seller_id">Seller ID</label>
                    <input id="adjust_seller_id" type="number" min="1" name="seller_id" value="<?= $sellerIdFilter > 0 ? $sellerIdFilter : '' ?>" required>
                </div>
                <div class="field">
                    <label for="adjust_direction">Direction</label>
                    <select id="adjust_direction" name="direction" required>
                        <option value="credit">Credit</option>
                        <option value="debit">Debit</option>
                    </select>
                </div>
                <div class="field">
                    <label for="adjust_amount">Amount</label>
                    <input id="adjust_amount" type="number" min="0.01" step="0.01" name="amount" required>
                </div>
                <div class="field">
                    <label for="adjust_note">Note</label>
                    <input id="adjust_note" type="text" name="note" required>
                </div>
                <button class="btn btn-primary" type="submit">Adjust balance</button>
            </form>
        </div>

        <div class="card">
            <div class="section-title">
                <h2>Recent ledger</h2>
                <?php if (!$hasLedger): ?><span class="badge badge-secondary">Unavailable</span><?php endif; ?>
            </div>
            <?php if ($recentLedger === []): ?>
                <div class="empty">No recent ledger entries found.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table style="min-width:720px;">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Seller</th>
                            <th>Type</th>
                            <th>Direction</th>
                            <th>Amount</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentLedger as $entry): ?>
                            <?php $entryCurrency = (string)($entry['currency'] ?? $summary['currency']); ?>
                            <tr>
                                <td>#<?= (int)($entry['id'] ?? 0) ?></td>
                                <td>#<?= (int)($entry['seller_id'] ?? 0) ?></td>
                                <td><?= h($entry['type'] ?? '—') ?></td>
                                <td><?= h($entry['direction'] ?? '—') ?></td>
                                <td><?= money_fmt($entry['amount'] ?? 0, $entryCurrency) ?></td>
                                <td><?= h($entry['created_at'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
