<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/seller_balance.php';

// ── Admin auth ────────────────────────────────────────────────────────────
$adminId = bv_seller_balance_current_user_id();

if (!bv_seller_balance_is_admin()) {
    http_response_code(403);
    exit('Access denied: admin only.');
}

if (!bv_seller_balance_tables_exist()) {
    http_response_code(503);
    echo '<h2>Seller balance tables not found.</h2>';
    echo '<p>Please run <code>migration/seller_balance_system.sql</code> first.</p>';
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSeller = (int)($_GET['seller_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 30;
$offset       = ($page - 1) * $perPage;

// ── Data ──────────────────────────────────────────────────────────────────
$flash         = bv_sb_flash_get();
$payoutRequests= bv_seller_balance_get_all_payout_requests($filterStatus, $perPage, $offset);
$allBalances   = bv_seller_balance_all_sellers_summary();

// Totals for overview cards
$totalPending   = array_sum(array_column($allBalances, 'pending_balance'));
$totalAvailable = array_sum(array_column($allBalances, 'available_balance'));
$totalHeld      = array_sum(array_column($allBalances, 'held_balance'));
$totalPaidOut   = array_sum(array_column($allBalances, 'paid_out_balance'));

$csrfToken = bv_seller_balance_csrf_token('admin_payout_action');

$statusOptions = ['', 'requested', 'approved', 'paid', 'rejected', 'cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seller Payouts — Bettavaro Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#090f0c;color:#e7ddca;font-size:13px;line-height:1.6}
a{color:#d8b56b;text-decoration:none}
.wrap{max-width:1280px;margin:0 auto;padding:28px 16px 60px}
.page-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:22px;flex-wrap:wrap}
.page-title{font-size:22px;font-weight:900;color:#f3efe6}
.btn{display:inline-flex;align-items:center;gap:5px;padding:0 14px;min-height:36px;border-radius:10px;font-weight:700;font-size:12px;cursor:pointer;border:1px solid transparent;transition:.12s;text-decoration:none}
.btn-gold{background:#d8b56b;color:#182018}.btn-gold:hover{background:#c9a45c}
.btn-green{background:rgba(91,192,138,.18);color:#5bc08a;border-color:rgba(91,192,138,.3)}.btn-green:hover{background:rgba(91,192,138,.28)}
.btn-red{background:rgba(224,108,108,.15);color:#e06c6c;border-color:rgba(224,108,108,.28)}.btn-red:hover{background:rgba(224,108,108,.25)}
.btn-outline{background:transparent;color:#e7ddca;border-color:rgba(229,201,138,.28)}.btn-outline:hover{border-color:#d8b56b}
.btn-sm{min-height:30px;padding:0 10px;font-size:11px;border-radius:8px}
.flash{padding:12px 15px;border-radius:12px;font-size:13px;margin-bottom:18px}
.flash-success{background:rgba(64,166,103,.14);border:1px solid rgba(64,166,103,.26);color:#c7f0d5}
.flash-error{background:rgba(214,92,92,.14);border:1px solid rgba(214,92,92,.26);color:#ffd5d5}
.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:22px}
.stat-card{border-radius:16px;padding:16px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03)}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b8070;font-weight:700;margin-bottom:6px}
.stat-value{font-size:18px;font-weight:900;color:#f3efe6}
.stat-card.avail .stat-value{color:#5bc08a}
.stat-card.held .stat-value{color:#d8b56b}
.tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.tab{padding:7px 16px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;border:1px solid rgba(255,255,255,.08);color:#8ea29a;transition:.12s}
.tab:hover{border-color:rgba(216,181,107,.3);color:#d8b56b}
.tab.active{background:rgba(216,181,107,.12);border-color:rgba(216,181,107,.35);color:#e0c98e}
.section{border-radius:18px;border:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02);overflow:hidden;margin-bottom:22px}
.section-head{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.06);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#e0c98e;display:flex;align-items:center;justify-content:space-between}
.table{width:100%;border-collapse:collapse}
.table th{padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b8070;border-bottom:1px solid rgba(255,255,255,.06)}
.table td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.table tr:last-child td{border-bottom:none}
.table tr:hover td{background:rgba(255,255,255,.015)}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
.badge-requested{background:rgba(216,181,107,.15);color:#e0c98e}
.badge-approved{background:rgba(91,192,138,.15);color:#5bc08a}
.badge-paid{background:rgba(91,192,138,.2);color:#5bc08a}
.badge-rejected{background:rgba(224,108,108,.15);color:#e06c6c}
.badge-cancelled{background:rgba(142,162,154,.12);color:#8ea29a}
.action-btns{display:flex;gap:5px;flex-wrap:wrap}
.empty{padding:36px;text-align:center;color:#6b8070}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:100;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#111d14;border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto}
.modal-title{font-size:16px;font-weight:900;color:#f3efe6;margin-bottom:18px}
.field{margin-bottom:14px}
.field label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;color:#d6ddcf}
.input,.select,.textarea{width:100%;min-height:40px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);color:#f3efe6;padding:9px 12px;font-size:13px;outline:none;font-family:inherit}
.select option{background:#1a2e24;color:#f3efe6}
.textarea{min-height:70px;resize:vertical}
.modal-actions{display:flex;gap:10px;margin-top:18px}
.modal-actions .btn{flex:1}
.seller-name{font-weight:700;color:#f3efe6}
.seller-email{font-size:11px;color:#8ea29a}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.stats-grid{grid-template-columns:1fr}.table td,.table th{padding:8px 8px;font-size:11px}}
</style>
</head>
<body>
<div class="wrap">

  <div class="page-header">
    <div class="page-title">🏦 Seller Payouts</div>
    <a href="dashboard.php" class="btn btn-outline">← Admin Dashboard</a>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="flash flash-<?= bv_sb_e($flash['type'] ?? 'error') ?>">
      <?= bv_sb_e($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>

  <!-- Platform Overview -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Total Pending</div>
      <div class="stat-value">$<?= bv_sb_e(number_format($totalPending, 2)) ?></div>
    </div>
    <div class="stat-card avail">
      <div class="stat-label">Total Available</div>
      <div class="stat-value">$<?= bv_sb_e(number_format($totalAvailable, 2)) ?></div>
    </div>
    <div class="stat-card held">
      <div class="stat-label">In Payout Requests</div>
      <div class="stat-value">$<?= bv_sb_e(number_format($totalHeld, 2)) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Paid Out</div>
      <div class="stat-value">$<?= bv_sb_e(number_format($totalPaidOut, 2)) ?></div>
    </div>
  </div>

  <!-- Status filter tabs -->
  <div class="tabs">
    <?php foreach ($statusOptions as $s): ?>
      <a href="?status=<?= urlencode($s) ?>"
         class="tab<?= $filterStatus === $s ? ' active' : '' ?>">
        <?= bv_sb_e($s === '' ? 'All' : ucfirst($s)) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Payout Requests Table -->
  <div class="section">
    <div class="section-head">
      Payout Requests
      <span style="font-weight:400;color:#6b8070;text-transform:none;font-size:12px;">
        <?= count($payoutRequests) ?> shown
      </span>
    </div>
    <?php if (empty($payoutRequests)): ?>
      <div class="empty">No payout requests<?= $filterStatus ? ' with status: ' . bv_sb_e($filterStatus) : '' ?>.</div>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Seller</th>
          <th>Requested</th>
          <th>Amount</th>
          <th>Method</th>
           <th>Destination</th>
          <th>Status</th>
          <th>Payment Ref</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payoutRequests as $pr): ?>
        <tr>
          <td><?= bv_sb_e((string)$pr['id']) ?></td>
          <td>
            <div class="seller-name">
              <?= bv_sb_e(trim($pr['seller_first'] . ' ' . $pr['seller_last'])) ?>
            </div>
            <div class="seller-email"><?= bv_sb_e((string)$pr['seller_email']) ?></div>
          </td>
          <td style="white-space:nowrap;font-size:12px;color:#8ea29a;">
            <?= bv_sb_e(substr((string)$pr['requested_at'], 0, 10)) ?>
          </td>
          <td><strong style="color:#f3efe6;">
            <?= bv_sb_e(bv_sb_money((float)$pr['amount'], (string)$pr['currency'])) ?>
          </strong></td>
          <td style="color:#8ea29a;">
            <?= bv_sb_e(str_replace('_', ' ', ucfirst((string)($pr['payout_method'] ?? '—')))) ?>
          </td>
          <td style="font-size:12px;color:#8ea29a;">
			<?php
              $dest = '—';
              $method = (string)($pr['payout_method'] ?? '');
              if ($method === 'bank_transfer') {
                  $dest = trim((string)($pr['bank_name'] ?? '') . ' / ' . (string)($pr['bank_account_number'] ?? '') . ' / ' . (string)($pr['bank_account_name'] ?? ''));
              } elseif ($method === 'promptpay') {
                  $dest = (string)($pr['promptpay_number'] ?? '—');
              } elseif ($method === 'wise') {
                  $dest = (string)($pr['seller_note'] ?? '—');
              } elseif ($method === 'other') {
                  $dest = (string)($pr['seller_note'] ?? '—');
              }
            ?>
            <?= bv_sb_e($dest !== '' ? $dest : '—') ?>            
          </td>
          <td>
            <span class="badge badge-<?= bv_sb_e((string)$pr['status']) ?>">
              <?= bv_sb_e(ucfirst((string)$pr['status'])) ?>
            </span>
          </td>
          <td style="font-size:12px;color:#8ea29a;">
            <?= bv_sb_e((string)($pr['payment_reference'] ?? '—')) ?>
          </td>
          <td>
            <div class="action-btns">
              <!-- View Details -->
              <button class="btn btn-outline btn-sm"
                onclick="openDetail(<?= (int)$pr['id'] ?>, <?= htmlspecialchars(json_encode($pr), ENT_QUOTES) ?>)">
                View
              </button>

              <?php if ($pr['status'] === 'approved'): ?>
                <!-- Mark Paid -->
                <button class="btn btn-green btn-sm"
                  onclick="openPayModal(<?= (int)$pr['id'] ?>, '<?= bv_sb_e(bv_sb_money((float)$pr['amount'], (string)$pr['currency'])) ?>')">
                  Mark Paid
                </button>
                <button class="btn btn-red btn-sm"
                  onclick="openRejectModal(<?= (int)$pr['id'] ?>)">
                  Reject
                </button>
              <?php endif; ?>

              <?php if ($pr['status'] === 'requested'): ?>
                <!-- Approve -->
                <button class="btn btn-outline btn-sm"
                  onclick="submitAction(<?= (int)$pr['id'] ?>, 'approve', '')">
                  Approve
                </button>
                <!-- Reject -->
                <button class="btn btn-red btn-sm"
                  onclick="openRejectModal(<?= (int)$pr['id'] ?>)">
                  Reject
                </button>
              <?php endif; ?>

            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Seller Balances Summary -->
  <div class="section">
    <div class="section-head">All Seller Balances</div>
    <?php if (empty($allBalances)): ?>
      <div class="empty">No seller balances yet.</div>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Seller</th>
          <th>Pending</th>
          <th>Available</th>
          <th>Held</th>
          <th>Paid Out</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allBalances as $sb): ?>
        <tr>
          <td>
            <div class="seller-name"><?= bv_sb_e(trim($sb['seller_first'] . ' ' . $sb['seller_last'])) ?></div>
            <div class="seller-email"><?= bv_sb_e((string)$sb['seller_email']) ?></div>
          </td>
          <td style="color:#8ea29a;">$<?= bv_sb_e(number_format((float)$sb['pending_balance'], 2)) ?></td>
          <td style="color:#5bc08a;font-weight:700;">$<?= bv_sb_e(number_format((float)$sb['available_balance'], 2)) ?></td>
          <td style="color:#d8b56b;">$<?= bv_sb_e(number_format((float)$sb['held_balance'], 2)) ?></td>
          <td style="color:#8ea29a;">$<?= bv_sb_e(number_format((float)$sb['paid_out_balance'], 2)) ?></td>
          <td>
            <div class="action-btns">
              <button class="btn btn-green btn-sm"
                onclick="openReleaseModal(<?= (int)$sb['seller_id'] ?>, '<?= bv_sb_e(trim($sb['seller_first'] . ' ' . $sb['seller_last'])) ?>', <?= number_format((float)$sb['pending_balance'], 4) ?>)">
                Release Eligible
              </button>
              <button class="btn btn-outline btn-sm"
                onclick="openAdjustModal(<?= (int)$sb['seller_id'] ?>, '<?= bv_sb_e(trim($sb['seller_first'] . ' ' . $sb['seller_last'])) ?>')">
                Adjust
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<!-- ── MODALS ─────────────────────────────────────────────────────────── -->

<!-- Mark Paid Modal -->
<div class="modal-overlay" id="modal-pay">
  <div class="modal">
    <div class="modal-title">💳 Mark Payout as Paid</div>
    <form method="POST" action="seller_payout_action.php">
      <input type="hidden" name="csrf_token" value="<?= bv_sb_e($csrfToken) ?>">
      <input type="hidden" name="action" value="mark_paid">
      <input type="hidden" name="payout_id" id="pay-payout-id" value="">
      <div class="field">
        <label>Payout Amount</label>
        <input class="input" type="text" id="pay-amount-display" readonly>
      </div>
      <div class="field">
        <label for="pay-method">Payment Method</label>
        <select class="select" id="pay-method" name="payment_method">
          <option value="bank_transfer">Bank Transfer</option>
          <option value="promptpay">PromptPay</option>
          <option value="wise">Wise</option>
          <option value="other">Other</option>
          <option value="manual">Manual</option>		  
        </select>
      </div>
      <div class="field">
        <label for="pay-ref">Payment Reference</label>
        <input class="input" type="text" id="pay-ref" name="payment_reference"
          placeholder="Required unless method is Manual/Other with admin note">
      </div>
      <div class="field">
        <label for="pay-note">Admin Note</label>
        <textarea class="textarea" id="pay-note" name="note" placeholder="Optional note"></textarea>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-green">✓ Confirm Paid</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-pay')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject/Cancel Modal -->
<div class="modal-overlay" id="modal-reject">
  <div class="modal">
    <div class="modal-title" id="reject-modal-title">Reject Payout Request</div>
    <form method="POST" action="seller_payout_action.php">
      <input type="hidden" name="csrf_token" value="<?= bv_sb_e($csrfToken) ?>">
      <input type="hidden" name="action" id="reject-action" value="reject">
      <input type="hidden" name="payout_id" id="reject-payout-id" value="">
      <div class="field">
        <label for="reject-note">Reason / Note <span style="color:#e06c6c">*</span></label>
        <textarea class="textarea" id="reject-note" name="note" required
          placeholder="Reason for rejection or cancellation"></textarea>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-red">Confirm</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-reject')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="modal-detail">
  <div class="modal">
    <div class="modal-title">Payout Request Details</div>
    <div id="detail-body" style="color:#d6ddcf;font-size:13px;line-height:1.8;"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-outline" onclick="closeModal('modal-detail')">Close</button>
    </div>
  </div>
</div>

<!-- Release Pending Modal -->
<div class="modal-overlay" id="modal-release">
  <div class="modal">
    <div class="modal-title">Release Pending Balance</div>
    <p style="color:#8ea29a;font-size:13px;margin-bottom:16px;" id="release-desc"></p>
    <form method="POST" action="seller_payout_action.php">
      <input type="hidden" name="csrf_token" value="<?= bv_sb_e($csrfToken) ?>">
      <input type="hidden" name="action" value="release_pending">
      <input type="hidden" name="seller_id" id="release-seller-id" value="">
      <input type="hidden" id="release-amount" name="amount" value="0">
      <div class="field">
        <label for="release-note">Note</label>
        <input class="input" type="text" id="release-note" name="note" placeholder="e.g. Clearing period passed — Order #123">
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-green">Release Eligible Funds</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-release')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Adjust Balance Modal -->
<div class="modal-overlay" id="modal-adjust">
  <div class="modal">
    <div class="modal-title">Manual Balance Adjustment</div>
    <p style="color:#8ea29a;font-size:13px;margin-bottom:16px;" id="adjust-desc"></p>
    <form method="POST" action="seller_payout_action.php">
      <input type="hidden" name="csrf_token" value="<?= bv_sb_e($csrfToken) ?>">
      <input type="hidden" name="action" value="adjust">
      <input type="hidden" name="seller_id" id="adjust-seller-id" value="">
      <div class="field">
        <label>Direction</label>
        <select class="select" name="direction">
          <option value="credit">Credit (add to available)</option>
          <option value="debit">Debit (remove from available)</option>
        </select>
      </div>
      <div class="field">
        <label>Amount</label>
        <input class="input" type="number" name="amount" step="0.01" min="0.01" required>
      </div>
      <div class="field">
        <label>Reason <span style="color:#e06c6c">*</span></label>
        <textarea class="textarea" name="note" required placeholder="Mandatory audit note for manual adjustment"></textarea>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-gold">Apply Adjustment</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-adjust')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>

function togglePayReferenceRequired() {
  const method = document.getElementById('pay-method').value;
  const ref = document.getElementById('pay-ref');
  ref.required = !(method === 'manual' || method === 'other');
}

function openModal(id)  { document.getElementById(id).classList.add('open');  }
function closeModal(id) { document.getElementById(id).classList.remove('open');}

function openPayModal(payoutId, amountDisplay) {
  document.getElementById('pay-payout-id').value    = payoutId;
  document.getElementById('pay-amount-display').value = amountDisplay;
togglePayReferenceRequired();  
  openModal('modal-pay');
}


function openRejectModal(payoutId, actionType = 'reject') {
  document.getElementById('reject-payout-id').value = payoutId;
  document.getElementById('reject-action').value    = actionType;
  document.getElementById('reject-modal-title').textContent =
    actionType === 'cancel' ? 'Cancel Payout Request' : 'Reject Payout Request';
  openModal('modal-reject');
}

function openDetail(payoutId, data) {
  const fields = [
    ['ID', data.id],
    ['Seller', (data.seller_first||'') + ' ' + (data.seller_last||'') + ' <span style="color:#8ea29a">' + (data.seller_email||'') + '</span>'],
    ['Amount', data.currency + ' ' + parseFloat(data.amount).toFixed(2)],
    ['Status', '<span class="badge badge-' + data.status + '">' + data.status + '</span>'],
    ['Method', (data.payout_method||'—').replace('_',' ')],
    ['Bank', data.bank_name||'—'],
    ['Account #', data.bank_account_number||'—'],
    ['Account Name', data.bank_account_name||'—'],
    ['PromptPay', data.promptpay_number||'—'],
    ['Seller Note', data.seller_note||'—'],
    ['Admin Note', data.admin_note||'—'],
    ['Payment Ref', data.payment_reference||'—'],
    ['Requested', data.requested_at||'—'],
    ['Approved', data.approved_at||'—'],
    ['Paid', data.paid_at||'—'],
    ['Rejected', data.rejected_at||'—'],
  ];
  let html = '<table style="width:100%;border-collapse:collapse;">';
  fields.forEach(([k,v]) => {
    html += '<tr><td style="padding:5px 0;color:#8ea29a;width:40%;font-size:12px;vertical-align:top">' +
      k + '</td><td style="padding:5px 0;color:#f3efe6;">' + (v||'—') + '</td></tr>';
  });
  html += '</table>';
  document.getElementById('detail-body').innerHTML = html;
  openModal('modal-detail');
}

function openReleaseModal(sellerId, sellerName, pendingAmt) {
  document.getElementById('release-seller-id').value = sellerId;
  document.getElementById('release-desc').textContent =
    'Release pending balance for ' + sellerName + '. Pending: USD ' + pendingAmt.toFixed(2);
  document.getElementById('release-amount').value = pendingAmt.toFixed(2);
  openModal('modal-release');
}

function openAdjustModal(sellerId, sellerName) {
  document.getElementById('adjust-seller-id').value = sellerId;
  document.getElementById('adjust-desc').textContent =
    'Manually adjust available_balance for ' + sellerName + '. All changes are logged.';
  openModal('modal-adjust');
}

function submitAction(payoutId, action, note) {
  if (!confirm('Confirm: ' + action + ' payout #' + payoutId + '?')) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'seller_payout_action.php';
  [
    ['csrf_token', '<?= bv_sb_e($csrfToken) ?>'],
    ['action',     action],
    ['payout_id',  payoutId],
    ['note',       note],
  ].forEach(([k, v]) => {
    const i = document.createElement('input');
    i.type = 'hidden'; i.name = k; i.value = v;
    form.appendChild(i);
  });
  document.body.appendChild(form);
  form.submit();
}

// Close modals on overlay click
document.getElementById('pay-method').addEventListener('change', togglePayReferenceRequired);
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.classList.remove('open');
  });
});
</script>
</body>
</html>
