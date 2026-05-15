<?php
declare(strict_types=1);

/**
 * Bettavaro — Seller Balance / Wallet / Payout System
 * Core library.  Include once per request.
 *
 * seller_id throughout = users.id of the seller user
 *
 * Ledger types:
 *   earning            – net earning added to pending
 *   platform_fee       – pending debit of platform commission
 *   pending_release    – pending → available (2 entries: debit pending + credit available)
 *   refund_hold        – amount held back pending refund decision
 *   refund_deduction   – final deduction after refund processed
 *   adjustment_credit  – manual credit by admin
 *   adjustment_debit   – manual debit by admin
 *   payout_request     – available → held (2 entries)
 *   payout_paid        – held → paid_out (2 entries)
 *   payout_cancelled   – held → available (2 entries)
 *   tax_withholding    – future Phase 2
 */

// ---------------------------------------------------------------------------
// BOOTSTRAP — PDO connection
// ---------------------------------------------------------------------------

$sellerFeePromoFile = __DIR__ . '/seller_fee_promotion.php';
if (is_file($sellerFeePromoFile)) {
    require_once $sellerFeePromoFile;
}
unset($sellerFeePromoFile);

if (!function_exists('_bv_sb_request_context_meta')) {
    function _bv_sb_request_context_meta(): array
    {
        return [
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];
    }
}

if (!function_exists('bv_seller_balance_pdo')) {
    function bv_seller_balance_pdo(): PDO
    {
        // Reuse existing platform PDO if available
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }

        // Try global $pdo (common pattern in projects without DI)
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // Attempt to bootstrap from project config files
        $root = dirname(__DIR__);
        $candidates = [
            $root . '/includes/db.php',
            $root . '/config/database.php',
            $root . '/config.php',
            $root . '/includes/config.php',
            $root . '/bootstrap.php',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                require_once $f;
                break;
            }
        }

        // Re-check after potential bootstrap
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        throw new RuntimeException(
            '[seller_balance] Cannot obtain PDO connection. ' .
            'Ensure bv_member_pdo() or global $pdo is available before including seller_balance.php.'
        );
    }
}

// ---------------------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_setting')) {
    function bv_seller_balance_get_setting(string $key, mixed $default = null): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT setting_value FROM seller_balance_settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$key] = $row ? (string)$row['setting_value'] : (string)($default ?? '');
        } catch (Throwable) {
            $cache[$key] = (string)($default ?? '');
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_balance_commission_rate')) {
    function bv_seller_balance_commission_rate(): float
    {
        return (float)bv_seller_balance_get_setting('platform_commission_rate', '0.10');
    }
}

if (!function_exists('bv_seller_balance_default_currency')) {
    function bv_seller_balance_default_currency(): string
    {
        return bv_seller_balance_get_setting('default_currency', 'USD');
    }
}

// ---------------------------------------------------------------------------
// TABLES CHECK
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_tables_exist')) {
    function bv_seller_balance_tables_exist(): bool
    {
        try {
            $pdo = bv_seller_balance_pdo();
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name IN (
                     'seller_balances','seller_ledger',
                     'seller_payout_requests','seller_payout_transactions'
                 )"
            );
            return (int)$stmt->fetchColumn() >= 4;
        } catch (Throwable) {
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// CURRENT USER HELPERS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_current_user_id')) {
    function bv_seller_balance_current_user_id(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $candidates = [
            $_SESSION['user']['id'] ?? null,
            $_SESSION['admin']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
        ];

        foreach ($candidates as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                return (int)$id;
            }
        }

        return 0;
    }
}

if (!function_exists('bv_seller_balance_current_user_role')) {
    function bv_seller_balance_current_user_role(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $roleCandidates = [
            $_SESSION['admin_role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['admin_user']['role'] ?? null,
            $_SESSION['current_admin']['role'] ?? null,
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['role'] ?? null,
        ];

        foreach ($roleCandidates as $role) {
            $role = strtolower(trim((string)$role));
            if ($role !== '') {
                return $role;
            }
        }

        return '';
    }
}

if (!function_exists('bv_seller_balance_is_seller')) {
    function bv_seller_balance_is_seller(): bool
    {
        return bv_seller_balance_current_user_role() === 'seller';
    }
}

if (!function_exists('bv_seller_balance_is_admin')) {
    function bv_seller_balance_is_admin(): bool
    {
        $role = strtolower(trim((string) bv_seller_balance_current_user_role()));

        return in_array($role, ['admin', 'super_admin', 'superadmin', 'owner'], true);
    }
}

// ---------------------------------------------------------------------------
// SELLER BALANCE — GET / ENSURE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get')) {
    /**
     * Get balance row for a seller. Returns null if not found.
     */
    function bv_seller_balance_get(int $sellerId): ?array
    {
        if ($sellerId <= 0) {
            return null;
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1'
            );
            $stmt->execute([$sellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('bv_seller_balance_ensure')) {
    /**
     * Ensure a seller_balances row exists. Returns the current row.
     * Uses INSERT IGNORE + re-fetch to be race-condition-safe.
     */
    function bv_seller_balance_ensure(int $sellerId, string $currency = ''): array
    {
        if ($currency === '') {
            $currency = bv_seller_balance_default_currency();
        }
        $pdo = bv_seller_balance_pdo();
        $pdo->prepare(
            'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
        )->execute([$sellerId, $currency]);

        $stmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1');
        $stmt->execute([$sellerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

// ---------------------------------------------------------------------------
// LEDGER — INTERNAL INSERT (must be called inside a DB transaction)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_insert_ledger')) {
    /**
     * Internal: insert one ledger row within an already-open transaction.
     * Returns the new ledger id.
     *
     * @param array{
     *   seller_id: int,
     *   type: string,
     *   balance_type: string,
     *   direction: string,
     *   amount: float,
     *   currency: string,
     *   balance_before: float,
     *   balance_after: float,
     *   reference_type?: string|null,
     *   reference_id?: int|null,
     *   idempotency_key?: string|null,
     *   note?: string|null,
     *   meta_json?: array|null,
     *   created_by_type?: string|null,
     *   created_by_id?: int|null,
     * } $e
     */
    function _bv_sb_insert_ledger(PDO $pdo, array $e): int
    {
        $meta = isset($e['meta_json']) ? json_encode($e['meta_json'], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO seller_ledger
             (seller_id, type, balance_type, direction, amount, currency,
              balance_before, balance_after, reference_type, reference_id,
              idempotency_key, note, meta_json, created_by_type, created_by_id)
             VALUES
             (:seller_id, :type, :balance_type, :direction, :amount, :currency,
              :bb, :ba, :ref_type, :ref_id,
              :idem, :note, :meta, :cbt, :cbi)'
        );
        $stmt->execute([
            ':seller_id'   => $e['seller_id'],
            ':type'        => $e['type'],
            ':balance_type'=> $e['balance_type'],
            ':direction'   => $e['direction'],
            ':amount'      => round((float)($e['amount'] ?? 0), 4),
            ':currency'    => $e['currency'] ?? 'USD',
            ':bb'          => round((float)($e['balance_before'] ?? 0), 4),
            ':ba'          => round((float)($e['balance_after']  ?? 0), 4),
            ':ref_type'    => $e['reference_type'] ?? null,
            ':ref_id'      => isset($e['reference_id']) ? (int)$e['reference_id'] : null,
            ':idem'        => $e['idempotency_key'] ?? null,
            ':note'        => $e['note'] ?? null,
            ':meta'        => $meta,
            ':cbt'         => $e['created_by_type'] ?? 'system',
            ':cbi'         => isset($e['created_by_id']) ? (int)$e['created_by_id'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('_bv_sb_table_exists')) {
    function _bv_sb_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('_bv_sb_column_exists')) {
    function _bv_sb_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('_bv_sb_ledger_exists')) {
    function _bv_sb_ledger_exists(PDO $pdo, string $idempotencyKey): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM seller_ledger WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('_bv_sb_insert_ledger_once')) {
    function _bv_sb_insert_ledger_once(PDO $pdo, array $entry): int
    {
        $key = (string)($entry['idempotency_key'] ?? '');
        if ($key !== '' && _bv_sb_ledger_exists($pdo, $key)) {
            return 0;
        }
        return _bv_sb_insert_ledger($pdo, $entry);
    }
}

if (!function_exists('bv_seller_balance_create_entries_from_paid_order')) {
    function bv_seller_balance_create_entries_from_paid_order(int $orderId): array
    {
        $result = ['created_or_touched' => 0, 'skipped' => 0, 'entry_ids' => []];
        if ($orderId <= 0) {
            return $result;
        }

        $pdo = bv_seller_balance_pdo();
        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries')) {
            return $result;
        }

        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return $result;
        }

        $hasOiSellerId = _bv_sb_column_exists($pdo, 'order_items', 'seller_id');
        $hasOiListingId = _bv_sb_column_exists($pdo, 'order_items', 'listing_id');
        $hasOiLineTotal = _bv_sb_column_exists($pdo, 'order_items', 'line_total');
        $hasOiUnitPrice = _bv_sb_column_exists($pdo, 'order_items', 'unit_price');
        $hasOiPrice = _bv_sb_column_exists($pdo, 'order_items', 'price');
        $hasOiQty = _bv_sb_column_exists($pdo, 'order_items', 'quantity');
        $hasOiCurrency = _bv_sb_column_exists($pdo, 'order_items', 'currency');
        $hasOiStatus = _bv_sb_column_exists($pdo, 'order_items', 'status');
        $hasOiFulfillmentStatus = _bv_sb_column_exists($pdo, 'order_items', 'fulfillment_status');
        $hasOrderCurrency = _bv_sb_column_exists($pdo, 'orders', 'currency');
        $hasListingsSellerId = _bv_sb_column_exists($pdo, 'listings', 'seller_id');

        $itemSql = 'SELECT oi.*';
        if ($hasListingsSellerId && $hasOiListingId) {
            $itemSql .= ', l.seller_id AS listing_seller_id';
        } else {
            $itemSql .= ', NULL AS listing_seller_id';
        }
        $itemSql .= ' FROM order_items oi';
        if ($hasListingsSellerId && $hasOiListingId) {
            $itemSql .= ' LEFT JOIN listings l ON l.id = oi.listing_id';
        }
        $itemSql .= ' WHERE oi.order_id = ?';

        $itemStmt = $pdo->prepare($itemSql);
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $insertSql = "INSERT INTO seller_balance_entries
            (seller_id, order_id, order_item_id, listing_id, amount, currency, status, risk_score, risk_flags_json, hold_reason, release_at, source, created_at, updated_at)
            VALUES
            (:seller_id, :order_id, :order_item_id, :listing_id, :amount, :currency, 'pending', 0, :risk_flags_json, 'order_paid_hold', DATE_ADD(NOW(), INTERVAL 1 DAY), 'order_paid', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            listing_id = IF(status IN ('paid_out','processing'), listing_id, VALUES(listing_id)),
            amount = IF(status IN ('paid_out','processing'), amount, VALUES(amount)),
            currency = IF(status IN ('paid_out','processing'), currency, VALUES(currency)),
            release_at = IF(status IN ('paid_out','processing'), release_at, VALUES(release_at)),
            updated_at = NOW()";
        $insertStmt = $pdo->prepare($insertSql);

        foreach ($items as $item) {
            $itemStatus = strtolower(trim((string)($item['status'] ?? '')));
            $fulfillmentStatus = strtolower(trim((string)($item['fulfillment_status'] ?? '')));
            if (($hasOiStatus && in_array($itemStatus, ['cancelled', 'canceled', 'refunded'], true)) ||
                ($hasOiFulfillmentStatus && in_array($fulfillmentStatus, ['cancelled', 'canceled', 'refunded'], true))) {
                $result['skipped']++;
                continue;
            }

            $sellerId = 0;
            if ($hasOiSellerId) {
                $sellerId = (int)($item['seller_id'] ?? 0);
            }
            if ($sellerId <= 0) {
                $sellerId = (int)($item['listing_seller_id'] ?? 0);
            }
            if ($sellerId <= 0) {
                $result['skipped']++;
                continue;
            }

            $qty = $hasOiQty ? (float)($item['quantity'] ?? 0) : 0.0;
            $lineTotal = $hasOiLineTotal ? (float)($item['line_total'] ?? 0) : 0.0;
            $unitPrice = $hasOiUnitPrice ? (float)($item['unit_price'] ?? 0) : 0.0;
            $price = $hasOiPrice ? (float)($item['price'] ?? 0) : 0.0;
            $amount = 0.0;
            if ($lineTotal > 0) {
                $amount = $lineTotal;
            } elseif ($qty > 0 && $unitPrice > 0) {
                $amount = $qty * $unitPrice;
            } elseif ($qty > 0 && $price > 0) {
                $amount = $qty * $price;
            }
            $amount = round($amount, 4);
            if ($amount <= 0) {
                $result['skipped']++;
                continue;
            }

            $currency = '';
            if ($hasOiCurrency) {
                $currency = trim((string)($item['currency'] ?? ''));
            }
            if ($currency === '' && $hasOrderCurrency) {
                $currency = trim((string)($order['currency'] ?? ''));
            }
            if ($currency === '') {
                $currency = 'USD';
            }

            $itemId = (int)($item['id'] ?? 0);
            $listingId = $hasOiListingId ? (int)($item['listing_id'] ?? 0) : 0;

            $insertStmt->execute([
                ':seller_id' => $sellerId,
                ':order_id' => $orderId,
                ':order_item_id' => $itemId,
                ':listing_id' => $listingId > 0 ? $listingId : null,
                ':amount' => $amount,
                ':currency' => $currency,
                ':risk_flags_json' => json_encode((object)[], JSON_UNESCAPED_UNICODE),
            ]);

            $lookup = $pdo->prepare('SELECT id FROM seller_balance_entries WHERE seller_id = ? AND order_id = ? AND order_item_id = ? LIMIT 1');
            $lookup->execute([$sellerId, $orderId, $itemId]);
            $entryId = (int)$lookup->fetchColumn();
            if ($entryId > 0) {
                $result['entry_ids'][] = $entryId;
            }
            $result['created_or_touched']++;
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// ORDER PAID — MAIN HOOK (called by order_paid_handler.php)
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_process_order_paid')) {
    /**
     * Process seller-owned order_items when an order is paid.
     * Earning is gross credit to pending; platform_fee is a pending debit.
     */
    function bv_seller_balance_process_order_paid(int $orderId): array
    {
        if ($orderId <= 0 || !bv_seller_balance_tables_exist()) {
            return [];
        }

        $pdo = bv_seller_balance_pdo();
        $commissionRate = bv_seller_balance_commission_rate();
        $processed = [];

        $stmt = $pdo->prepare(
            'SELECT oi.id AS item_id,
                    oi.seller_id,
                    oi.line_total,
                    oi.currency,
                    oi.item_title,
                    o.order_code
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = ?
               AND oi.seller_id > 0'
        );
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $itemId = (int)$item['item_id'];
            $sellerId = (int)$item['seller_id'];
            $currency = (string)($item['currency'] ?? 'USD');
            $gross = round((float)($item['line_total'] ?? 0), 4);

            if ($gross <= 0 || $sellerId <= 0) {
                continue;
            }

            $earningKey = 'order_paid_earning:' . $orderId . ':' . $itemId;
            $feeKey = 'order_paid_platform_fee:' . $orderId . ':' . $itemId;
            $legacyKey = 'order_paid:' . $orderId . ':' . $itemId;

            $defaultPlatformFeePercent = (float)$commissionRate;
            if ($defaultPlatformFeePercent <= 1) {
                $defaultPlatformFeePercent *= 100;
            }
            $defaultPlatformFeePercent = round($defaultPlatformFeePercent, 4);
            $platformFee = round($gross * ((float)$commissionRate), 4);   
            $platformFeePercent = $defaultPlatformFeePercent;
            $feeMode = 'default';
			
            if (function_exists('bv_seller_fee_promo_apply_fee_amount')) {
                try {
                     $feeDecision = bv_seller_fee_promo_apply_fee_amount(
                        (float)$gross,
                        (float)$defaultPlatformFeePercent,
                        (int)$sellerId
                    );

                    if (is_array($feeDecision)) {
                        $platformFee = round((float)($feeDecision['fee_amount'] ?? $platformFee), 4);
                        $platformFeePercent = (float)($feeDecision['percent_used'] ?? $defaultPlatformFeePercent);
                        $feeMode = (string)($feeDecision['mode'] ?? 'default');
                    }
                } catch (Throwable) {
                   // Keep the existing default platform fee.
                }
  
            }
            $platformFeeWaived = $platformFee <= 0;
            $netEarning = round($gross - $platformFee, 4);

            try {
                $pdo->beginTransaction();

                $legacyExists = _bv_sb_ledger_exists($pdo, $legacyKey);
                $earningExists = _bv_sb_ledger_exists($pdo, $earningKey);
                $feeExists = _bv_sb_ledger_exists($pdo, $feeKey);

                if ($legacyExists) {
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                 if ($earningExists && ($feeExists || $platformFee <= 0)) { 
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                $pdo->prepare(
                    'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
                )->execute([$sellerId, $currency]);

                $balRow = $pdo->prepare(
                    'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE'
                );
                $balRow->execute([$sellerId]);
                $balance = $balRow->fetch(PDO::FETCH_ASSOC);

                $pendingBefore = round((float)($balance['pending_balance'] ?? 0), 4);
                 $pendingCursor = $pendingBefore;
                $pendingDelta = 0.0;
                $grossDelta = 0.0;
                $feeDelta = 0.0;

                 if (!$earningExists) {
                    $pendingAfterEarning = round($pendingCursor + $gross, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'earning',
                        'balance_type'    => 'pending',
                        'direction'       => 'credit',
                        'amount'          => $gross,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterEarning,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $earningKey,
                        'note'            => 'Order #' . $orderId . ' item #' . $itemId . ': ' . ($item['item_title'] ?? ''),
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_code'      => $item['order_code'] ?? '',
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                           'platform_fee_percent' => $platformFeePercent,
                            'platform_fee_mode' => $feeMode,							
                            'platform_fee'    => $platformFee,
	                        'platform_fee_waived' => $platformFeeWaived,						
                            'net_earning'     => $netEarning,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterEarning;
                    $pendingDelta = round($pendingDelta + $gross, 4);
                    $grossDelta = $gross;
                }
                 if (!$feeExists && $platformFee <= 0 && function_exists('bv_seller_balance_log')) {
                    bv_seller_balance_log('platform_fee_waived', [
                        'order_id' => $orderId,
                        'order_item_id' => $itemId,
                        'seller_id' => $sellerId,
                        'gross' => $gross,
                        'platform_fee_percent' => $platformFeePercent,
                        'platform_fee_mode' => $feeMode,
                       'platform_fee' => $platformFee,						
                    ]);
                }

               if (!$feeExists && $platformFee > 0) {
                    $pendingAfterFee = round($pendingCursor - $platformFee, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'platform_fee',
                        'balance_type'    => 'pending',
                        'direction'       => 'debit',
                        'amount'          => $platformFee,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterFee,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $feeKey,
                        'note'            => 'Platform fee ' . round($platformFeePercent, 2) . '% on order #' . $orderId,
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                            'platform_fee_percent' => $platformFeePercent,
                            'platform_fee_mode' => $feeMode,							
                            'platform_fee'    => $platformFee,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterFee;
                    $pendingDelta = round($pendingDelta - $platformFee, 4);
                    $feeDelta = $platformFee;
                }
				
                if ($pendingDelta !== 0.0 || $grossDelta !== 0.0 || $feeDelta !== 0.0) {
                    $pdo->prepare(
                        'UPDATE seller_balances
                         SET pending_balance = pending_balance + :pending_delta,
                             total_earned_gross = total_earned_gross + :gross_delta,
                             total_platform_fee = total_platform_fee + :fee_delta,
                             currency = :currency
                         WHERE seller_id = :sid'
                    )->execute([
                        ':pending_delta' => $pendingDelta,
                        ':gross_delta' => $grossDelta,
                        ':fee_delta' => $feeDelta,
                        ':currency' => $currency,
                        ':sid' => $sellerId,
                    ]);
                }
                $pdo->commit();
                $processed[] = $itemId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[seller_balance] process_order_paid failed for order #' . $orderId . ' item #' . $itemId . ': ' . $e->getMessage());
            }
        }

         try {
            $entryResult = bv_seller_balance_create_entries_from_paid_order($orderId);
            if (function_exists('bv_seller_balance_log')) {
                bv_seller_balance_log('order_paid_entries_sync', [
                    'order_id' => $orderId,
                    'result' => $entryResult,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[seller_balance] create_entries_from_paid_order failed for order #' . $orderId . ': ' . $e->getMessage());
        }

        return $processed;
    }
}
// ---------------------------------------------------------------------------
// PENDING → AVAILABLE RELEASE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_find_releasable_ledger_rows')) {
    function bv_seller_balance_find_releasable_ledger_rows(?int $sellerId = null): array
    {
        $pdo = bv_seller_balance_pdo();
        $days = max(0, (int)bv_seller_balance_get_setting('payout_clearance_days', '3'));
        $params = [$days];
        $sellerSql = '';
        if ($sellerId !== null && $sellerId > 0) {
          $sellerSql = ' AND e.seller_id = ?';
            $params[] = $sellerId; 
        }

        $activeRefundSql = '';
        if (_bv_sb_table_exists($pdo, 'order_refunds') && _bv_sb_table_exists($pdo, 'order_refund_items')) {
            $activeRefundSql = "
               AND NOT EXISTS (
                   SELECT 1
                   FROM order_refund_items ri
                   JOIN order_refunds r ON r.id = ri.refund_id
                   WHERE ri.order_item_id = oi.id
                     AND r.status IN ('draft','pending_approval','partially_approved','approved','processing','partially_refunded')
               )";
        }

        $paymentStatusSql = '';
        if (_bv_sb_column_exists($pdo, 'orders', 'payment_status')) {
            $paymentStatusSql = " AND o.payment_status IN ('paid','succeeded','complete','completed')";
        }

        // SAFETY: only release when fulfillment is explicitly confirmed.
        // Empty/NULL fulfillment_status means not shipped → do NOT release.
        $fulfillmentSql = '';
        if (_bv_sb_column_exists($pdo, 'order_items', 'fulfillment_status')) {
            $fulfillmentSql = " AND oi.fulfillment_status IN ('shipped','completed','delivered')";
        }

        $sql = "SELECT e.seller_id,
                       e.reference_id AS order_item_id,
                       e.currency,
                       e.created_at,
                       oi.order_id,
                       o.order_code,
                       COALESCE(e.amount, 0) AS gross_amount,
                       COALESCE(f.pending_fee_amount, 0) AS fee_amount,
                       COALESCE(e.amount, 0) - COALESCE(f.pending_fee_amount, 0) AS net_amount 
                FROM seller_ledger e
                JOIN order_items oi ON oi.id = e.reference_id AND oi.seller_id = e.seller_id
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN (
                    SELECT seller_id,
                           reference_id,
                           SUM(amount) AS pending_fee_amount
                    FROM seller_ledger
                    WHERE type = 'platform_fee'
                      AND balance_type = 'pending'
                      AND direction = 'debit'
                      AND reference_type = 'order_item'
                    GROUP BY seller_id, reference_id
                ) f
                   ON f.seller_id = e.seller_id
                  AND f.reference_id = e.reference_id
                WHERE e.type = 'earning'
                  AND e.balance_type = 'pending'
                  AND e.direction = 'credit'
                  AND e.reference_type = 'order_item'
                   AND e.amount > 0
                  AND e.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND o.status IN ('confirmed','paid','processing','shipped','completed')
                  $paymentStatusSql
                  $fulfillmentSql
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger prd
                      WHERE prd.idempotency_key = CONCAT('pending_release_debit:', e.seller_id, ':', e.reference_id)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger prc
                      WHERE prc.idempotency_key = CONCAT('pending_release_credit:', e.seller_id, ':', e.reference_id)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger rh
                      WHERE rh.seller_id = e.seller_id
                        AND rh.type = 'refund_hold'
                        AND rh.reference_type = 'order_item'
                        AND rh.reference_id = e.reference_id
                  )
                  $sellerSql
                  $activeRefundSql
                HAVING net_amount > 0
                ORDER BY e.created_at ASC, e.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bv_seller_balance_release_pending_by_order_item')) {
    function bv_seller_balance_release_pending_by_order_item(int $orderItemId): bool
    {
        if ($orderItemId <= 0) {
            return false;
        }
        $rows = bv_seller_balance_find_releasable_ledger_rows(null);
        foreach ($rows as $row) {
            if ((int)$row['order_item_id'] === $orderItemId) {
                return bv_seller_balance_release_pending_for_row($row);
            }
        }
        return false;
    }
}

if (!function_exists('bv_seller_balance_release_pending_for_seller')) {
    function bv_seller_balance_release_pending_for_seller(int $sellerId, string $currency = 'USD'): array
    {
        if ($sellerId <= 0) {
            return [
                'released_count' => 0,
                'released_amount' => 0.0,
                'skipped_count' => 0,
                'errors' => ['Invalid seller_id'],
            ];
        }
 
        $result = [
            'released_count' => 0,
            'released_amount' => 0.0,
            'skipped_count' => 0,
            'errors' => [],
        ];
        foreach (bv_seller_balance_find_releasable_ledger_rows($sellerId) as $row) {
            if ($currency !== '' && strcasecmp((string)$row['currency'], $currency) !== 0) {
				                $result['skipped_count']++;
                continue;
            }
            $amount = round((float)($row['net_amount'] ?? 0), 4);
            if ($amount <= 0) {
                $result['skipped_count']++;
                continue;
            }
            try {
                if (bv_seller_balance_release_pending_for_row($row)) {
                    $result['released_count']++;
                    $result['released_amount'] = round(((float)$result['released_amount']) + $amount, 4);
                } else {
                    $result['skipped_count']++;
                }
            } catch (Throwable $e) {
                $result['skipped_count']++;
                $result['errors'][] = $e->getMessage();
            }
        }
        return $result;
    }
}

if (!function_exists('bv_seller_balance_release_pending_for_row')) {
    function bv_seller_balance_release_pending_for_row(array $row): bool
    {
        $sellerId = (int)($row['seller_id'] ?? 0);
        $orderItemId = (int)($row['order_item_id'] ?? 0);
        $amount = round((float)($row['net_amount'] ?? 0), 4);
        $currency = (string)($row['currency'] ?? 'USD');
        if ($sellerId <= 0 || $orderItemId <= 0 || $amount <= 0) {
            return false;
        }

        $pdo = bv_seller_balance_pdo();
        $debitKey = 'pending_release_debit:' . $sellerId . ':' . $orderItemId;
        $creditKey = 'pending_release_credit:' . $sellerId . ':' . $orderItemId;

        try {
            $pdo->beginTransaction();
            if (_bv_sb_ledger_exists($pdo, $debitKey) || _bv_sb_ledger_exists($pdo, $creditKey)) {
                $pdo->rollBack();
                return false;
            }

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $pendingNow = round((float)($balance['pending_balance'] ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $releaseAmt = min($amount, $pendingNow);
            if ($releaseAmt <= 0) {
                $pdo->rollBack();
                return false;
            }

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'pending',
                'direction' => 'debit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $pendingNow,
                'balance_after' => round($pendingNow - $releaseAmt, 4),
                'reference_type' => 'order_item',
                'reference_id' => $orderItemId,
                'idempotency_key' => $debitKey,
                'note' => 'Pending released for order item #' . $orderItemId,
                'meta_json' => ['order_id' => (int)($row['order_id'] ?? 0), 'order_code' => (string)($row['order_code'] ?? '')],
                'created_by_type' => 'system',
            ]);

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'available',
                'direction' => 'credit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow + $releaseAmt, 4),
                'reference_type' => 'order_item',
                'reference_id' => $orderItemId,
                'idempotency_key' => $creditKey,
                'note' => 'Pending released for order item #' . $orderItemId,
                'meta_json' => ['order_id' => (int)($row['order_id'] ?? 0), 'order_code' => (string)($row['order_code'] ?? '')],
                'created_by_type' => 'system',
            ]);

            $pdo->prepare(
                'UPDATE seller_balances
                 SET pending_balance = pending_balance - ?,
                     available_balance = available_balance + ?
                 WHERE seller_id = ?'
            )->execute([$releaseAmt, $releaseAmt, $sellerId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_pending row failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_release_pending')) {
    function bv_seller_balance_release_pending(
        int $sellerId,
        float $amount,
        string $referenceNote = '',
        string $idempotencyKey = '',
        int $adminId = 0
    ): bool {
        if ($sellerId <= 0 || $amount <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $amount = round($amount, 4);
        $debitKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':debit';
        $creditKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':credit';

        try {
            $pdo->beginTransaction();
            if (_bv_sb_ledger_exists($pdo, $debitKey) || _bv_sb_ledger_exists($pdo, $creditKey)) {
                $pdo->rollBack();
                return true;
            }
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }
            $pendingNow = round((float)$balance['pending_balance'], 4);
            $availableNow = round((float)$balance['available_balance'], 4);
            $releaseAmt = min($amount, $pendingNow);
            if ($releaseAmt <= 0) {
                $pdo->rollBack();
                return false;
            }
            $currency = (string)($balance['currency'] ?? 'USD');
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'pending',
                'direction' => 'debit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $pendingNow,
                'balance_after' => round($pendingNow - $releaseAmt, 4),
                'idempotency_key' => $debitKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'available',
                'direction' => 'credit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow + $releaseAmt, 4),
                'idempotency_key' => $creditKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            $pdo->prepare(
                'UPDATE seller_balances
                 SET pending_balance = pending_balance - :amt,
                     available_balance = available_balance + :amt
                 WHERE seller_id = :sid'
            )->execute([':amt' => $releaseAmt, ':sid' => $sellerId]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_pending failed for seller #' . $sellerId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT REQUEST — SELLER SUBMITS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_request_payout')) {
    /**
     * Seller requests a payout from available_balance.
     * Moves amount: available_balance -> held_balance.
     */
    function bv_seller_balance_request_payout(int $sellerId, float $amount, array $details = []): int
    {
        if ($sellerId <= 0 || $amount <= 0) {
            return 0;
        }

        $minAmount = (float)bv_seller_balance_get_setting('payout_min_amount', '10.00');
        if ($amount < $minAmount) {
            throw new RuntimeException('Payout amount must be at least ' . number_format($minAmount, 2));
        }
        if (bv_seller_balance_get_setting('payout_enabled', '1') !== '1') {
            throw new RuntimeException('Payout requests are temporarily disabled.');
        }
		
        $method = (string)($details['payout_method'] ?? '');
        $allowedMethods = ['promptpay', 'bank_transfer', 'wise', 'other'];
        if (!in_array($method, $allowedMethods, true)) {
            throw new RuntimeException('Invalid payout method.');
        }

        $bankName = trim((string)($details['bank_name'] ?? ''));
        $bankAccountNumber = trim((string)($details['bank_account_number'] ?? ''));
        $bankAccountName = trim((string)($details['bank_account_name'] ?? ''));
        $promptpay = preg_replace('/\D+/', '', (string)($details['promptpay_number'] ?? ''));
        $sellerNote = trim((string)($details['seller_note'] ?? ''));

        if ($method === 'promptpay' && !in_array(strlen($promptpay), [10, 13], true)) {
            throw new RuntimeException('PromptPay number must contain 10 or 13 digits.');
        }
        if ($method === 'bank_transfer') {
            if ($bankName === '' || $bankAccountNumber === '' || $bankAccountName === '') {
                throw new RuntimeException('Bank name, account number, and account name are required for bank transfer.');
            }
        }
        if ($method === 'wise') {
            if ($sellerNote === '' && trim((string)($details['wise_email'] ?? '')) === '') {
                throw new RuntimeException('Wise payout requires a note or email for destination details.');
            }
        }		

        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                throw new RuntimeException('No balance record found for seller.');
            }

            $currency = (string)($balance['currency'] ?? 'USD');
          $requestCurrency = strtoupper((string)($details['currency'] ?? $currency));
            if ($requestCurrency !== '' && strtoupper($currency) !== $requestCurrency) {
                $pdo->rollBack();
                throw new RuntimeException('Currency mismatch for payout request.');
            }
			
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $requestAmt = round($amount, 4);

            if ($requestAmt <= 0 || $requestAmt > $availableNow) { 
                $pdo->rollBack();
                throw new RuntimeException('Requested amount exceeds available balance. Available: ' . number_format($availableNow, 2));
            }

            $openStmt = $pdo->prepare(
                "SELECT id FROM seller_payout_requests
                 WHERE seller_id = ? AND status IN ('requested','approved')
                 LIMIT 1 FOR UPDATE"
            );
            $openStmt->execute([$sellerId]);
            if ($openStmt->fetchColumn()) {
                $pdo->rollBack();
                throw new RuntimeException('You already have an open payout request. Please wait for it to be processed.');
            }

            $pdo->prepare(
                'INSERT INTO seller_payout_requests
                 (seller_id, amount, currency, status, bank_name, bank_account_number,
                  bank_account_name, promptpay_number, payout_method, seller_note)
                 VALUES
                 (:sid, :amt, :cur, :status, :bank, :acct, :acct_name, :pp, :method, :note)'
            )->execute([
                ':sid' => $sellerId,
                ':amt' => $requestAmt,
                ':cur' => $currency,
                ':status' => 'requested',
                ':bank' => $method === 'bank_transfer' ? $bankName : null,
                ':acct' => $method === 'bank_transfer' ? $bankAccountNumber : null,
                ':acct_name' => $method === 'bank_transfer' ? $bankAccountName : null,
                ':pp' => $method === 'promptpay' ? $promptpay : null,
                ':method' => $method,
                ':note' => $sellerNote,
            ]);
            $payoutRequestId = (int)$pdo->lastInsertId();
			
            $metaBase = _bv_sb_request_context_meta() + [
                'action' => 'payout_request',
                'payout_request_id' => $payoutRequestId,
                'seller_id' => $sellerId,
                'old_status' => null,
                'new_status' => 'requested',
                'payment_method' => $method,
                'payment_reference' => null,
            ];

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'payout_request',
                'balance_type' => 'available',
                'direction' => 'debit',
                'amount' => $requestAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow - $requestAmt, 4),
                'reference_type' => 'payout_request',
                'reference_id' => $payoutRequestId,
                'idempotency_key' => 'payout_request_available:' . $payoutRequestId,
                'note' => 'Payout request #' . $payoutRequestId,
                'meta_json' => $metaBase,
                'created_by_type' => $details['created_by_type'] ?? 'seller',
                'created_by_id' => $details['created_by_id'] ?? $sellerId,
            ]);

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'payout_request',
                'balance_type' => 'held',
                'direction' => 'credit',
                'amount' => $requestAmt,
                'currency' => $currency,
                'balance_before' => $heldNow,
                'balance_after' => round($heldNow + $requestAmt, 4),
                'reference_type' => 'payout_request',
                'reference_id' => $payoutRequestId,
                'idempotency_key' => 'payout_request_held:' . $payoutRequestId,
                'note' => 'Payout request #' . $payoutRequestId . ' held',
                 'meta_json' => $metaBase,
                'created_by_type' => $details['created_by_type'] ?? 'seller',
                'created_by_id' => $details['created_by_id'] ?? $sellerId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances
                 SET available_balance = available_balance - :amt,
                     held_balance = held_balance + :amt
                 WHERE seller_id = :sid'
            )->execute([':amt' => $requestAmt, ':sid' => $sellerId]);

            $pdo->commit();
            return $payoutRequestId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT — ADMIN: APPROVE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_approve_payout')) {
    function bv_seller_balance_approve_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
			
           $sellerId = (int)$req['seller_id'];
            $balRow = $pdo->prepare('SELECT seller_id FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            if (!$balRow->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                return false;
            }
			
            if ((string)$req['status'] === 'approved') {
                $pdo->commit();
                return true;
            }
            if ((string)$req['status'] !== 'requested') {
                $pdo->rollBack();
                return false;
			}

            $set = ["status = 'approved'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'approved_at')) {
                $set[] = 'approved_at = COALESCE(approved_at, NOW())';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'approved_by')) {
                $set[] = 'approved_by = :admin_id';
            } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $set[] = 'admin_id = :admin_id';
            }

            $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . " WHERE id = :id AND status = 'requested'";
            $pdo->prepare($sql)->execute([
                ':admin_id' => $adminId,
                ':note' => $note !== '' ? "
[Approved] " . $note : '',
                ':id' => $payoutId,
            ]);
			
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] approve_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_reject_payout')) {
    function bv_seller_balance_reject_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            if ((string)$req['status'] === 'rejected') {
                $pdo->commit();
                return true;
            }
            if (!in_array((string)$req['status'], ['requested','approved'], true)) {
                $pdo->rollBack();
                return false;
            }

            $sellerId = (int)$req['seller_id'];
            $amount = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            $heldKey = 'payout_reject_held:' . $payoutId;
            $availableKey = 'payout_reject_available:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            if ($amount > $heldNow) {
                $pdo->rollBack();
                throw new RuntimeException('Held balance is lower than payout amount; cannot reject safely.');
            }

             $metaBase = _bv_sb_request_context_meta() + [
                'action' => 'payout_reject',
                'payout_request_id' => $payoutId,
                'admin_id' => $adminId,
                'old_status' => (string)$req['status'],
                'new_status' => 'rejected',
                'payment_method' => (string)($req['payout_method'] ?? ''),
                'payment_reference' => null,
            ];

            if ($amount > 0 && !_bv_sb_ledger_exists($pdo, $heldKey)) {
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_cancelled',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
					'balance_after' => round($heldNow - $amount, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Payout #' . $payoutId . ' rejected - returned to available',
                    'meta_json' => $metaBase,					
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_cancelled',
                    'balance_type' => 'available',
                    'direction' => 'credit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'balance_before' => $availableNow,
                    'balance_after' => round($availableNow + $amount, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $availableKey,
                    'note' => 'Payout #' . $payoutId . ' rejected - returned to available',
                    'meta_json' => $metaBase,
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                $pdo->prepare(
                    'UPDATE seller_balances
                     SET held_balance = held_balance - :amt,
                         available_balance = available_balance + :amt
                     WHERE seller_id = :sid'
                )->execute([':amt' => $amount, ':sid' => $sellerId]);
            }

             $set = ["status = 'rejected'", "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_at')) {
                $set[] = 'rejected_at = COALESCE(rejected_at, NOW())';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'rejected_by')) {
                $set[] = 'rejected_by = :admin_id';
            } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $set[] = 'admin_id = :admin_id';
            }

            $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute([
                ':admin_id' => $adminId,
                ':note' => "
[Rejected] " . ($note ?: 'No reason given'),
                ':id' => $payoutId,
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] reject_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_mark_payout_paid')) {
    function bv_seller_balance_mark_payout_paid(
        int $payoutId,
        int $adminId,
        string $paymentReference = '',
        string $paymentMethod = 'bank_transfer',
        string $note = ''
    ): bool {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            if ((string)$req['status'] === 'paid') {
                $pdo->commit();
                return true;
            }
            if ((string)$req['status'] !== 'approved') {
                $pdo->rollBack();
                return false;
            }

           $paymentMethod = trim($paymentMethod);
            $isManual = in_array($paymentMethod, ['manual', 'other'], true);
            if (trim($paymentReference) === '' && !$isManual) {
                $pdo->rollBack();
                throw new RuntimeException('Payment reference is required for non-manual payouts.');
            }
            if ($isManual && trim($note) === '') {
                $pdo->rollBack();
                throw new RuntimeException('Admin note is required for manual/other payout without payment reference.');
            }
			
            $sellerId = (int)$req['seller_id'];
            $amount = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            $heldKey = 'payout_paid_held:' . $payoutId;
            $paidKey = 'payout_paid_paid_out:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $paidOutNow = round((float)($balance['paid_out_balance'] ?? 0), 4);
             if ($amount > $heldNow) {
                $pdo->rollBack();
                throw new RuntimeException('Held balance is lower than payout amount; cannot mark paid safely.');
            }
			
          $metaBase = _bv_sb_request_context_meta() + [
                'action' => 'payout_paid',
                'payout_request_id' => $payoutId,
                'admin_id' => $adminId,
                'old_status' => (string)$req['status'],
                'new_status' => 'paid',
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
            ];			

            if (!_bv_sb_ledger_exists($pdo, $heldKey)) {
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_paid',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $amount, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Payout #' . $payoutId . ' paid - ref: ' . $paymentReference,
                    'meta_json' => $metaBase,					
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_paid',
                    'balance_type' => 'paid_out',
                    'direction' => 'credit',
                    'amount' => $amount, 
                    'currency' => $currency,
                    'balance_before' => $paidOutNow,
                    'balance_after' => round($paidOutNow + $amount, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $paidKey,
                    'note' => 'Payout #' . $payoutId . ' paid - ref: ' . $paymentReference,
                    'meta_json' => $metaBase,					
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                $pdo->prepare(
                    'UPDATE seller_balances
                     SET held_balance = held_balance - :amt,
                         paid_out_balance = paid_out_balance + :amt
                     WHERE seller_id = :sid'
                )->execute([':amt' => $amount, ':sid' => $sellerId]);
            }

            $pdo->prepare(
                'INSERT IGNORE INTO seller_payout_transactions
                 (payout_request_id, seller_id, amount, currency, payment_method,
                  payment_reference, bank_name, bank_account_number, bank_account_name,
                  admin_id, note)
                 VALUES
                 (:prid, :sid, :amt, :cur, :method, :ref, :bank, :acct, :acct_name, :aid, :note)'
            )->execute([
                ':prid' => $payoutId,
                ':sid' => $sellerId,
                ':amt' => $amount,
                ':cur' => $currency,
                ':method' => $paymentMethod,
                ':ref' => $paymentReference,
                ':bank' => $req['bank_name'] ?? null,
                ':acct' => $req['bank_account_number'] ?? null,
                ':acct_name' => $req['bank_account_name'] ?? null,
                ':aid' => $adminId,
                ':note' => $note,
            ]);

             $set = ["status = 'paid'", 'payment_reference = :ref', "admin_note = CONCAT(COALESCE(admin_note,''), :note)"];
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'paid_at')) {
                $set[] = 'paid_at = COALESCE(paid_at, NOW())';
            }
            if (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'paid_by')) {
                $set[] = 'paid_by = :admin_id';
            } elseif (_bv_sb_column_exists($pdo, 'seller_payout_requests', 'admin_id')) {
                $set[] = 'admin_id = :admin_id';
            }

            $sql = 'UPDATE seller_payout_requests SET ' . implode(', ', $set) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute([
                ':admin_id' => $adminId,
                ':ref' => $paymentReference,
                ':note' => $note !== '' ? "
[Paid] " . $note : '',
                ':id' => $payoutId,
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] mark_payout_paid #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_cancel_payout')) {
    function bv_seller_balance_cancel_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        return bv_seller_balance_reject_payout($payoutId, $adminId, $note ?: 'Cancelled by admin');
    }
}
// ---------------------------------------------------------------------------
// REFUND STUBS — Phase 1 (prepared, not enforced)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_refund_seller_exposures')) {
    function _bv_sb_refund_seller_exposures(PDO $pdo, int $refundId): array
    {
        if ($refundId <= 0 || !_bv_sb_table_exists($pdo, 'order_refunds') || !_bv_sb_table_exists($pdo, 'order_refund_items')) {
            return [];
        }
        $amountExpr = 'COALESCE(NULLIF(ri.actual_refund_amount, 0), NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refund_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refunded_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }

        $stmt = $pdo->prepare(
            "SELECT r.id AS refund_id,
                    r.order_id,
                    r.status,
                    r.currency,
                    ri.order_item_id,
                    oi.seller_id,
                    oi.line_total,
                    $amountExpr AS refund_amount,
                    COALESCE(e.amount, oi.line_total, 0) AS gross_amount,
                    COALESCE(f.amount, 0) AS fee_amount
             FROM order_refunds r
             JOIN order_refund_items ri ON ri.refund_id = r.id
             JOIN order_items oi ON oi.id = ri.order_item_id AND oi.seller_id > 0
             LEFT JOIN seller_ledger e
               ON e.seller_id = oi.seller_id AND e.type = 'earning'
              AND e.reference_type = 'order_item' AND e.reference_id = oi.id
              JOIN seller_ledger f
               ON f.seller_id = oi.seller_id AND f.type = 'platform_fee'
              AND f.reference_type = 'order_item' AND f.reference_id = oi.id
             WHERE r.id = :rid"
        );
        $stmt->execute([':rid' => $refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bySeller = [];
        foreach ($rows as $row) {
            $sellerId = (int)($row['seller_id'] ?? 0);
            $lineTotal = round((float)($row['line_total'] ?? 0), 4);
            $refundAmount = round((float)($row['refund_amount'] ?? 0), 4);
            if ($sellerId <= 0 || $refundAmount <= 0) {
                continue;
            }
            $gross = round((float)($row['gross_amount'] ?? $lineTotal), 4);
            $fee = round((float)($row['fee_amount'] ?? 0), 4);
            $net = max(0.0, round($gross - $fee, 4));
            $ratio = $lineTotal > 0 ? min(1.0, $refundAmount / $lineTotal) : 1.0;
            $exposure = round($net * $ratio, 4);
            if ($exposure <= 0) {
                continue;
            }
            if (!isset($bySeller[$sellerId])) {
                $bySeller[$sellerId] = [
                    'seller_id' => $sellerId,
                    'currency' => (string)($row['currency'] ?? 'USD'),
                    'amount' => 0.0,
                    'items' => [],
                ];
            }
            $bySeller[$sellerId]['amount'] = round($bySeller[$sellerId]['amount'] + $exposure, 4);
            $bySeller[$sellerId]['items'][] = [
                'order_item_id' => (int)$row['order_item_id'],
                'refund_amount' => $refundAmount,
                'seller_exposure' => $exposure,
            ];
        }
        return array_values($bySeller);
    }
}

if (!function_exists('bv_seller_balance_hold_for_refund')) {
    function bv_seller_balance_hold_for_refund(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $availableKey = 'refund_hold_available:' . $refundId . ':' . $sellerId;
                $heldKey = 'refund_hold_held:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $availableKey) || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }

                $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)')
                    ->execute([$sellerId, (string)$group['currency']]);
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $holdAmt = min($needed, $availableNow);
                $shortage = round($needed - $holdAmt, 4);
                if ($holdAmt <= 0 && $shortage <= 0) {
                    continue;
                }
                $meta = ['refund_id' => $refundId, 'needed' => $needed, 'held' => $holdAmt, 'shortage' => $shortage, 'items' => $group['items']];

                if ($holdAmt > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => round($availableNow - $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'held',
                        'direction' => 'credit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $heldNow,
                        'balance_after' => round($heldNow + $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $heldKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare(
                        'UPDATE seller_balances SET available_balance = available_balance - :amt, held_balance = held_balance + :amt WHERE seller_id = :sid'
                    )->execute([':amt' => $holdAmt, ':sid' => $sellerId]);
                } elseif ($shortage > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => 0,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => $availableNow,
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold shortage #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] hold_for_refund #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_apply_refund_deduction')) {
    function bv_seller_balance_apply_refund_deduction(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                $currency = (string)$group['currency'];
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $doneKey = 'refund_deduction:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $doneKey . ':held') || _bv_sb_ledger_exists($pdo, $doneKey . ':available') || _bv_sb_ledger_exists($pdo, $doneKey . ':pending')) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $remaining = $needed;
                foreach ([
                    'held' => 'held_balance',
                    'available' => 'available_balance',
                    'pending' => 'pending_balance',
                ] as $balanceType => $column) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $before = round((float)($balance[$column] ?? 0), 4);
                    $deduct = min($remaining, $before);
                    if ($deduct <= 0) {
                        continue;
                    }
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_deduction',
                        'balance_type' => $balanceType,
                        'direction' => 'debit',
                        'amount' => $deduct,
                        'currency' => $currency,
                        'balance_before' => $before,
                        'balance_after' => round($before - $deduct, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $doneKey . ':' . $balanceType,
                        'note' => 'Refund deduction #' . $refundId,
                        'meta_json' => ['refund_id' => $refundId, 'needed' => $needed, 'items' => $group['items']],
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare('UPDATE seller_balances SET ' . $column . ' = ' . $column . ' - :amt WHERE seller_id = :sid')
                        ->execute([':amt' => $deduct, ':sid' => $sellerId]);
                    $balance[$column] = round($before - $deduct, 4);
                    $remaining = round($remaining - $deduct, 4);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] apply_refund_deduction #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_release_refund_hold')) {
    function bv_seller_balance_release_refund_hold(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $stmt = $pdo->prepare(
            "SELECT seller_id, currency, SUM(CASE WHEN balance_type = 'held' AND direction = 'credit' THEN amount ELSE 0 END) AS held_amount
             FROM seller_ledger
             WHERE type = 'refund_hold' AND reference_type = 'refund' AND reference_id = ?
             GROUP BY seller_id, currency"
        );
        $stmt->execute([$refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($rows as $row) {
                $sellerId = (int)$row['seller_id'];
                $amount = round((float)$row['held_amount'], 4);
                $currency = (string)$row['currency'];
                $heldKey = 'refund_hold_release_held:' . $refundId . ':' . $sellerId;
                $availableKey = 'refund_hold_release_available:' . $refundId . ':' . $sellerId;
                if ($sellerId <= 0 || $amount <= 0 || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $releaseAmt = min($amount, $heldNow);
                if ($releaseAmt <= 0) {
                    continue;
                }
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'available',
                    'direction' => 'credit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $availableNow,
                    'balance_after' => round($availableNow + $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $availableKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                $pdo->prepare('UPDATE seller_balances SET held_balance = held_balance - :amt, available_balance = available_balance + :amt WHERE seller_id = :sid')
                    ->execute([':amt' => $releaseAmt, ':sid' => $sellerId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_refund_hold #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// ADMIN MANUAL ADJUSTMENT
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_admin_adjust')) {
    /**
     * Add or subtract from available_balance manually.
     * $direction: 'credit' or 'debit'
     */
    function bv_seller_balance_admin_adjust(
        int    $sellerId,
        float  $amount,
        string $direction,
        string $note,
        int    $adminId
    ): bool {
        if ($sellerId <= 0 || $amount <= 0 || !in_array($direction, ['credit','debit'], true)) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id) VALUES (?)')->execute([$sellerId]);
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);

            $availNow = round((float)($balance['available_balance'] ?? 0), 4);
            $delta    = round($amount, 4);
            $newAvail = $direction === 'credit'
                ? round($availNow + $delta, 4)
                : round($availNow - $delta, 4);

            if ($newAvail < 0) {
                $pdo->rollBack();
                throw new RuntimeException('Debit would result in negative available_balance.');
            }

            $type = $direction === 'credit' ? 'adjustment_credit' : 'adjustment_debit';

            _bv_sb_insert_ledger($pdo, [
                'seller_id'      => $sellerId,
                'type'           => $type,
                'balance_type'   => 'available',
                'direction'      => $direction,
                'amount'         => $delta,
                'currency'       => (string)($balance['currency'] ?? 'USD'),
                'balance_before' => $availNow,
                'balance_after'  => $newAvail,
                'note'           => $note,
                'created_by_type'=> 'admin',
                'created_by_id'  => $adminId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances SET available_balance = :avail WHERE seller_id = :sid'
            )->execute([':avail' => $newAvail, ':sid' => $sellerId]);

            $pdo->commit();
            return true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

// ---------------------------------------------------------------------------
// AUTO PAYOUT REQUESTS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_create_auto_payout_requests')) {
    function bv_seller_balance_create_auto_payout_requests(): int
    {
        if (bv_seller_balance_get_setting('auto_payout_enabled', '0') !== '1') {
            return 0;
        }

        $pdo = bv_seller_balance_pdo();
        $minAmount = max(0.0, (float)bv_seller_balance_get_setting('auto_payout_min_amount', '50.00'));
        $method = bv_seller_balance_get_setting('payout_method', 'manual_bank_transfer');
        $created = 0;

        $stmt = $pdo->prepare(
            "SELECT sb.*
             FROM seller_balances sb
             WHERE sb.available_balance >= :min_amount
               AND NOT EXISTS (
                   SELECT 1 FROM seller_payout_requests pr
                   WHERE pr.seller_id = sb.seller_id
                     AND pr.currency = sb.currency
                     AND pr.status IN ('requested','approved')
               )
             ORDER BY sb.seller_id ASC"
        );
        $stmt->execute([':min_amount' => $minAmount]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $sellerId = (int)$row['seller_id'];
            $amount = round((float)$row['available_balance'], 4);
            if ($sellerId <= 0 || $amount < $minAmount) {
                continue;
            }
            try {
                $id = bv_seller_balance_request_payout($sellerId, $amount, [
                    'payout_method' => $method,
                    'seller_note' => 'Automatic payout request',
                    'created_by_type' => 'system',
                    'created_by_id' => null,
                ]);
                if ($id > 0) {
                    $created++;
                }
            } catch (Throwable $e) {
                error_log('[seller_balance] auto payout seller #' . $sellerId . ': ' . $e->getMessage());
            }
        }

        return $created;
    }
}

// ---------------------------------------------------------------------------
// READ QUERIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_ledger')) {
    function bv_seller_balance_get_ledger(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_ledger
                 WHERE seller_id = ?
                 ORDER BY id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$sellerId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_payout_requests')) {
    function bv_seller_balance_get_payout_requests(
        int    $sellerId,
        string $status  = '',
        int    $limit   = 20,
        int    $offset  = 0
    ): array {
        try {
            $where = 'WHERE seller_id = ?';
            $params = [$sellerId];
            if ($status !== '') {
                $where .= ' AND status = ?';
                $params[] = $status;
            }
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_payout_requests $where ORDER BY id DESC LIMIT ? OFFSET ?"
            );
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_all_payout_requests')) {
    function bv_seller_balance_get_all_payout_requests(
        string $status = '',
        int    $limit  = 50,
        int    $offset = 0
    ): array {
        try {
            $where  = $status !== '' ? "WHERE pr.status = ?" : '';
            $params = $status !== '' ? [$status] : [];
            $params[] = $limit;
            $params[] = $offset;

            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT pr.*,
                        u.email          AS seller_email,
                        u.first_name     AS seller_first,
                        u.last_name      AS seller_last
                 FROM seller_payout_requests pr
                 JOIN users u ON u.id = pr.seller_id
                 $where
                 ORDER BY pr.id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_all_sellers_summary')) {
    function bv_seller_balance_all_sellers_summary(): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->query(
                "SELECT sb.*,
                        u.email      AS seller_email,
                        u.first_name AS seller_first,
                        u.last_name  AS seller_last
                 FROM seller_balances sb
                 JOIN users u ON u.id = sb.seller_id
                 ORDER BY sb.available_balance DESC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_reconcile_seller')) {
    function bv_seller_balance_reconcile_seller(int $sellerId, string $currency = 'USD'): array
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $result = [
            'ok' => false,
            'ledger' => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0],
            'balance' => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0],
            'diff' => ['pending' => 0.0, 'available' => 0.0, 'held' => 0.0, 'paid_out' => 0.0],
        ];
        if ($sellerId <= 0) {
            return $result;
        }

        try {
            $pdo = bv_seller_balance_pdo();
            $balStmt = $pdo->prepare(
                'SELECT pending_balance, available_balance, held_balance, paid_out_balance
                 FROM seller_balances
                 WHERE seller_id = ? AND currency = ?
                 LIMIT 1'
            );
            $balStmt->execute([$sellerId, $currency]);
            $balance = $balStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $result['balance'] = [
                'pending' => round((float)($balance['pending_balance'] ?? 0), 4),
                'available' => round((float)($balance['available_balance'] ?? 0), 4),
                'held' => round((float)($balance['held_balance'] ?? 0), 4),
                'paid_out' => round((float)($balance['paid_out_balance'] ?? 0), 4),
            ];

            $ledgerStmt = $pdo->prepare(
                "SELECT balance_type,
                        SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS net_amount
                 FROM seller_ledger
                 WHERE seller_id = ? AND currency = ?
                 GROUP BY balance_type"
            );
            $ledgerStmt->execute([$sellerId, $currency]);
            foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bt = (string)($row['balance_type'] ?? '');
                $net = round((float)($row['net_amount'] ?? 0), 4);
                if ($bt === 'pending') {
                    $result['ledger']['pending'] = $net;
                } elseif ($bt === 'available') {
                    $result['ledger']['available'] = $net;
                } elseif ($bt === 'held') {
                    $result['ledger']['held'] = $net;
                } elseif ($bt === 'paid_out') {
                    $result['ledger']['paid_out'] = $net;
                }
            }

            foreach ($result['ledger'] as $key => $ledgerAmt) {
                $result['diff'][$key] = round($result['balance'][$key] - $ledgerAmt, 4);
            }
            $result['ok'] = (
                abs($result['diff']['pending']) < 0.0001
                && abs($result['diff']['available']) < 0.0001
                && abs($result['diff']['held']) < 0.0001
                && abs($result['diff']['paid_out']) < 0.0001
            );
        } catch (Throwable) {
            // keep default false
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_csrf_token')) {
    function bv_seller_balance_csrf_token(string $action = 'seller_balance'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key = '_bvsb_csrf_' . $action;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }
}

if (!function_exists('bv_seller_balance_verify_csrf')) {
    function bv_seller_balance_verify_csrf(string $token, string $action = 'seller_balance'): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key    = '_bvsb_csrf_' . $action;
        $stored = (string)($_SESSION[$key] ?? '');
        return $stored !== '' && hash_equals($stored, $token);
    }
}

// ---------------------------------------------------------------------------
// UTILITIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_sb_e')) {
    function bv_sb_e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('bv_sb_money')) {
    function bv_sb_money(float|string $amount, string $currency = 'USD'): string
    {
        return $currency . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('bv_sb_redirect')) {
    function bv_sb_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bv_sb_flash_set')) {
    function bv_sb_flash_set(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_bvsb_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('bv_sb_flash_get')) {
    function bv_sb_flash_get(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $flash = $_SESSION['_bvsb_flash'] ?? [];
        unset($_SESSION['_bvsb_flash']);
        return $flash;
    }
}

// ===========================================================================
// CRON COMPATIBILITY LAYER
// Required by payout_scheduler.php and payout_processor.php.
// All functions wrapped in function_exists() — zero naming conflicts.
//
// TABLE SEPARATION — IMPORTANT:
//   seller_balances       = legacy aggregate wallet (pending_balance,
//                           available_balance, held_balance, paid_out_balance,
//                           total_earned_gross, total_platform_fee …).
//                           NEVER touched by any code below this line.
//
//   seller_balance_entries = NEW line-level payout lifecycle table.
//                            One row per seller/order_item.
//                            Tracks: pending → on_hold → available
//                                    → processing → paid_out.
//
// DDL — run once before enabling cron jobs:
//
// CREATE TABLE IF NOT EXISTS `seller_balance_entries` (
//   `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
//   `seller_id`        BIGINT UNSIGNED  NOT NULL,
//   `order_id`         BIGINT UNSIGNED  NOT NULL,
//   `order_item_id`    BIGINT UNSIGNED  DEFAULT NULL,
//   `listing_id`       BIGINT UNSIGNED  DEFAULT NULL,
//   `amount`           DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
//   `currency`         CHAR(3)          NOT NULL DEFAULT 'USD',
//   `status`           ENUM('pending','on_hold','available','processing',
//                           'paid_out','failed','cancelled','frozen')
//                                       NOT NULL DEFAULT 'pending',
//   `risk_score`       INT              NOT NULL DEFAULT 0,
//   `risk_flags_json`  TEXT             DEFAULT NULL,
//   `hold_reason`      VARCHAR(191)     DEFAULT NULL,
//   `release_at`       DATETIME         DEFAULT NULL,
//   `available_at`     DATETIME         DEFAULT NULL,
//   `paid_out_at`      DATETIME         DEFAULT NULL,
//   `payout_id`        BIGINT UNSIGNED  DEFAULT NULL,
//   `source`           VARCHAR(50)      NOT NULL DEFAULT 'order_paid',
//   `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP(),
//   `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP()
//                                       ON UPDATE CURRENT_TIMESTAMP(),
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `uniq_seller_balance_entry_item` (`seller_id`,`order_id`,`order_item_id`),
//   KEY `idx_seller_balance_entries_status_release` (`status`,`release_at`),
//   KEY `idx_seller_balance_entries_seller_status`  (`seller_id`,`status`),
//   KEY `idx_seller_balance_entries_order`          (`order_id`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
//
// ===========================================================================

// ---------------------------------------------------------------------------
// PDO alias
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_db')) {
    function bv_seller_balance_db(): PDO
    {
        return bv_seller_balance_pdo();
    }
}

// ---------------------------------------------------------------------------
// Timestamp
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_now')) {
    function bv_seller_balance_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

// ---------------------------------------------------------------------------
// File log
// ---------------------------------------------------------------------------
if (!function_exists('_bvsbe_log_path')) {
    function _bvsbe_log_path(): string
    {
        $private = dirname(__DIR__, 2) . '/private_html/logs';
        if (is_dir($private) && is_writable($private)) {
            return $private . '/seller_balance.log';
        }
        $pub = dirname(__DIR__) . '/logs';
        if (!is_dir($pub)) {
            @mkdir($pub, 0775, true);
        }
        return $pub . '/seller_balance.log';
    }
}

if (!function_exists('bv_seller_balance_log')) {
    function bv_seller_balance_log(string $event, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(_bvsbe_log_path(), $line, FILE_APPEND | LOCK_EX);
        @error_log(trim($line));
    }
}

// ---------------------------------------------------------------------------
// Schema helpers — thin wrappers over existing _bv_sb_* internals
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_table_exists')) {
    function bv_seller_balance_table_exists(PDO $pdo, string $table): bool
    {
        return _bv_sb_table_exists($pdo, $table);
    }
}

if (!function_exists('bv_seller_balance_has_col')) {
    function bv_seller_balance_has_col(PDO $pdo, string $table, string $col): bool
    {
        return _bv_sb_column_exists($pdo, $table, $col);
    }
}

// ---------------------------------------------------------------------------
// Transaction helpers
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_begin')) {
    function bv_seller_balance_begin(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }
}

if (!function_exists('bv_seller_balance_commit')) {
    function bv_seller_balance_commit(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }
}

if (!function_exists('bv_seller_balance_rollback')) {
    function bv_seller_balance_rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

// ---------------------------------------------------------------------------
// Structured event log (writes to seller_payout_logs if table exists)
// seller_balance_id column references seller_balance_entries.id logically;
// no FK is enforced so the log table is safe even if entries are missing.
// ---------------------------------------------------------------------------
if (!function_exists('_bvsbe_write_log')) {
    function _bvsbe_write_log(
        PDO $pdo,
        string $event,
        string $message = '',
        array $context = [],
        ?int $sellerId = null,
        ?int $payoutId = null,
        ?int $entryId = null,
        ?int $orderId = null
    ): void {
        if (!_bv_sb_table_exists($pdo, 'seller_payout_logs')) {
            bv_seller_balance_log($event, array_merge($context, [
                'seller_id' => $sellerId,
                'payout_id' => $payoutId,
                'entry_id'  => $entryId,
                'order_id'  => $orderId,
                'message'   => $message,
            ]));
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO seller_payout_logs
                 (seller_id, payout_id, seller_balance_id, order_id,
                  event, message, context_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sellerId,
                $payoutId,
                $entryId,
                $orderId,
                substr($event, 0, 80),
                $message ?: null,
                $context
                    ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                bv_seller_balance_now(),
            ]);
        } catch (Throwable $e) {
            bv_seller_balance_log($event, array_merge($context, [
                'log_write_error' => $e->getMessage(),
            ]));
        }
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_release_eligible()
//
// Moves seller_balance_entries rows: pending/on_hold → available.
// Fulfillment safety: only releases when fulfillment_status is explicitly
// shipped/completed/delivered. Empty or NULL blocks release.
// NEVER reads or writes to legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_release_eligible')) {
    function bv_seller_balance_release_eligible(PDO $pdo, int $limit = 100): array
    {
        $result = ['released' => 0, 'skipped' => 0, 'balance_ids' => []];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        $stmt = $pdo->prepare(
            "SELECT sbe.*
             FROM seller_balance_entries sbe
             WHERE sbe.status IN ('pending','on_hold')
               AND sbe.release_at <= ?
             ORDER BY sbe.release_at ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$rows) {
            return $result;
        }

        $hasOrderItems        = _bv_sb_table_exists($pdo, 'order_items');
        $hasFulfillmentStatus = $hasOrderItems
            && _bv_sb_column_exists($pdo, 'order_items', 'fulfillment_status');
        $hasOrders            = _bv_sb_table_exists($pdo, 'orders');
        $hasOrderRefunds      = _bv_sb_table_exists($pdo, 'order_refunds');

        foreach ($rows as $row) {
            $entryId  = (int) $row['id'];
            $orderId  = (int) $row['order_id'];
            $itemId   = (int) ($row['order_item_id'] ?? 0);
            $sellerId = (int) $row['seller_id'];

            $status = strtolower($row['status'] ?? '');
            if (in_array($status, ['frozen', 'cancelled', 'paid_out', 'available', 'processing'], true)) {
                $result['skipped']++;
                continue;
            }

            // Fulfillment safety: require explicit shipped/completed/delivered.
            // Empty or NULL fulfillment_status = not yet shipped → block release.
            if ($hasFulfillmentStatus && $itemId > 0) {
                $stmtFs = $pdo->prepare(
                    'SELECT fulfillment_status
                     FROM order_items
                     WHERE id = ? AND order_id = ? LIMIT 1'
                );
                $stmtFs->execute([$itemId, $orderId]);
                $fs = strtolower(trim((string) $stmtFs->fetchColumn()));
                if (!in_array($fs, ['shipped', 'completed', 'delivered'], true)) {
                    $result['skipped']++;
                    continue;
                }
            } elseif ($hasOrders) {
                // Fallback: check order status when no item-level tracking
                $stmtOs = $pdo->prepare(
                    'SELECT status FROM orders WHERE id = ? LIMIT 1'
                );
                $stmtOs->execute([$orderId]);
                $os = strtolower(trim((string) $stmtOs->fetchColumn()));
                if (!in_array($os, ['paid', 'confirmed', 'completed', 'shipped', 'delivered'], true)) {
                    $result['skipped']++;
                    continue;
                }
            }

            // Block release when an active refund exists for this order
            if ($hasOrderRefunds) {
                try {
                    $blockStatuses = [
                        'draft', 'pending_approval', 'partially_approved',
                        'approved', 'processing', 'partially_refunded', 'pending',
                    ];
                    $ph      = implode(',', array_fill(0, count($blockStatuses), '?'));
                    $stmtRef = $pdo->prepare(
                        "SELECT COUNT(*) FROM order_refunds
                         WHERE order_id = ? AND status IN ({$ph})"
                    );
                    $stmtRef->execute(array_merge([$orderId], $blockStatuses));
                    if ((int) $stmtRef->fetchColumn() > 0) {
                        $result['skipped']++;
                        continue;
                    }
                } catch (Throwable $e) {
                    // ignore schema mismatch
                }
            }

            try {
                $upd = $pdo->prepare(
                    "UPDATE seller_balance_entries
                     SET status = 'available', available_at = ?, updated_at = ?
                     WHERE id = ? AND status IN ('pending','on_hold')"
                );
                $upd->execute([$now, $now, $entryId]);

                if ($upd->rowCount() > 0) {
                    $result['released']++;
                    $result['balance_ids'][] = $entryId;
                    _bvsbe_write_log(
                        $pdo, 'entry_released',
                        "Entry {$entryId} released to available",
                        [],
                        $sellerId, null, $entryId, $orderId
                    );
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                bv_seller_balance_log('entry_release_error', [
                    'entry_id' => $entryId,
                    'error'    => $e->getMessage(),
                ]);
                $result['skipped']++;
            }
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_build_payout_queue()
//
// Groups available seller_balance_entries rows into payout queue batches.
// Idempotency key = md5 of sorted entry IDs (not date):
//   same set → same key (safe retry); new set → new key (no blocking).
// NEVER reads or writes to legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_build_payout_queue')) {
    function bv_seller_balance_build_payout_queue(PDO $pdo, int $limitSellers = 50): array
    {
        $result = ['created' => 0, 'payout_ids' => []];

        if (
            !_bv_sb_table_exists($pdo, 'seller_balance_entries') ||
            !_bv_sb_table_exists($pdo, 'seller_payout_queue')    ||
            !_bv_sb_table_exists($pdo, 'seller_payout_items')
        ) {
            return $result;
        }

        $now = bv_seller_balance_now();

        // Aggregate available entries per seller + currency
        $stmtAgg = $pdo->prepare(
            "SELECT seller_id, currency, SUM(amount) AS total_amount
             FROM seller_balance_entries
             WHERE status = 'available'
             GROUP BY seller_id, currency
             HAVING total_amount > 0
             ORDER BY total_amount DESC
             LIMIT " . max(1, $limitSellers)
        );
        $stmtAgg->execute();
        $groups = $stmtAgg->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$groups) {
            return $result;
        }

        foreach ($groups as $group) {
            $sellerId = (int) $group['seller_id'];
            $currency = (string) $group['currency'];

            if ($sellerId <= 0) {
                continue;
            }

            try {
                bv_seller_balance_begin($pdo);

                // Lock the available entries for this seller + currency
                $stmtEntries = $pdo->prepare(
                    "SELECT * FROM seller_balance_entries
                     WHERE seller_id = ? AND currency = ? AND status = 'available'
                     ORDER BY id ASC
                     FOR UPDATE"
                );
                $stmtEntries->execute([$sellerId, $currency]);
                $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (!$entries) {
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                $actualAmount = round(array_sum(array_column($entries, 'amount')), 2);
                if ($actualAmount <= 0) {
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                // Deterministic idempotency key from sorted entry IDs.
                // Same set → same key (safe retry). New entry added → new hash.
                $entryIds = array_map(static fn($e) => (int) $e['id'], $entries);
                sort($entryIds);
                $idemKey = 'payout:' . $sellerId . ':' . strtolower($currency)
                    . ':' . md5(implode(',', $entryIds));

                $stmtIns = $pdo->prepare(
                    "INSERT INTO seller_payout_queue
                     (seller_id, amount, currency, status, provider,
                      idempotency_key, scheduled_at, created_at, updated_at)
                     VALUES (?, ?, ?, 'pending', 'manual', ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = id"
                );
                $stmtIns->execute([
                    $sellerId, $actualAmount, $currency,
                    $idemKey, $now, $now, $now,
                ]);

                $payoutId = (int) $pdo->lastInsertId();

                if ($payoutId <= 0) {
                    // Exact same set already queued
                    bv_seller_balance_rollback($pdo);
                    continue;
                }

                // Link each entry to the payout and mark it processing
                foreach ($entries as $entry) {
                    $entryId  = (int) $entry['id'];
                    $entryAmt = (float) $entry['amount'];
                    $entryOid = (int) $entry['order_id'];
                    $entryIid = isset($entry['order_item_id'])
                        ? (int) $entry['order_item_id']
                        : null;

                    $pdo->prepare(
                        'INSERT IGNORE INTO seller_payout_items
                         (payout_id, seller_balance_id, seller_id, order_id,
                          order_item_id, amount, currency, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $payoutId, $entryId, $sellerId,
                        $entryOid, $entryIid, $entryAmt, $currency, $now,
                    ]);

                    // seller_balance_id in seller_payout_items = seller_balance_entries.id
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'processing', payout_id = ?, updated_at = ?
                         WHERE id = ? AND status = 'available'"
                    )->execute([$payoutId, $now, $entryId]);
                }

                bv_seller_balance_commit($pdo);

                $result['created']++;
                $result['payout_ids'][] = $payoutId;

                _bvsbe_write_log(
                    $pdo, 'payout_queue_created',
                    "Payout {$payoutId} queued for seller {$sellerId}: {$actualAmount} {$currency}",
                    ['amount' => $actualAmount, 'entry_count' => count($entries)],
                    $sellerId, $payoutId
                );
            } catch (Throwable $e) {
                bv_seller_balance_rollback($pdo);
                bv_seller_balance_log('payout_queue_error', [
                    'seller_id' => $sellerId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_mark_payout_success()
//
// Called only after real external confirmation (admin or webhook).
// Marks seller_balance_entries rows as paid_out.
// NEVER called automatically — payout_processor.php requires manual confirm.
// NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_mark_payout_success')) {
    function bv_seller_balance_mark_payout_success(
        PDO $pdo,
        int $payoutId,
        string $providerReference = ''
    ): array {
        $result = ['ok' => false, 'payout_id' => $payoutId];

        if (!_bv_sb_table_exists($pdo, 'seller_payout_queue')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            bv_seller_balance_begin($pdo);

            $stmtPay = $pdo->prepare(
                'SELECT * FROM seller_payout_queue WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmtPay->execute([$payoutId]);
            $payout = $stmtPay->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                throw new RuntimeException("Payout {$payoutId} not found.");
            }

            $status = strtolower($payout['status'] ?? '');
            if (!in_array($status, ['pending', 'processing'], true)) {
                throw new RuntimeException(
                    "Payout {$payoutId} status '{$status}' cannot be marked success."
                );
            }

            $pdo->prepare(
                "UPDATE seller_payout_queue
                 SET status = 'success',
                     provider_reference = ?,
                     processed_at = ?,
                     updated_at = ?
                 WHERE id = ?"
            )->execute([$providerReference ?: null, $now, $now, $payoutId]);

            // Mark the linked seller_balance_entries rows as paid_out
            if (_bv_sb_table_exists($pdo, 'seller_payout_items')) {
                $stmtItems = $pdo->prepare(
                    'SELECT seller_balance_id FROM seller_payout_items WHERE payout_id = ?'
                );
                $stmtItems->execute([$payoutId]);
                foreach ($stmtItems->fetchAll(PDO::FETCH_COLUMN) ?: [] as $entryId) {
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'paid_out', paid_out_at = ?, updated_at = ?
                         WHERE id = ? AND status IN ('processing','available')"
                    )->execute([$now, $now, (int) $entryId]);
                }
            }

            bv_seller_balance_commit($pdo);

            $sellerId = (int) ($payout['seller_id'] ?? 0);
            _bvsbe_write_log(
                $pdo, 'payout_success',
                "Payout {$payoutId} marked success. Ref: {$providerReference}",
                ['provider_reference' => $providerReference],
                $sellerId, $payoutId
            );

            $result['ok']        = true;
            $result['seller_id'] = $sellerId;
        } catch (Throwable $e) {
            bv_seller_balance_rollback($pdo);
            bv_seller_balance_log('payout_success_error', [
                'payout_id' => $payoutId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_mark_payout_failed()
//
// Returns linked seller_balance_entries rows to available (retryable).
// NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_mark_payout_failed')) {
    function bv_seller_balance_mark_payout_failed(
        PDO $pdo,
        int $payoutId,
        string $reason
    ): array {
        $result = ['ok' => false, 'payout_id' => $payoutId];

        if (!_bv_sb_table_exists($pdo, 'seller_payout_queue')) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            bv_seller_balance_begin($pdo);

            $stmtPay = $pdo->prepare(
                'SELECT * FROM seller_payout_queue WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmtPay->execute([$payoutId]);
            $payout = $stmtPay->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                throw new RuntimeException("Payout {$payoutId} not found.");
            }

            $pdo->prepare(
                "UPDATE seller_payout_queue
                 SET status = 'failed',
                     failure_reason = ?,
                     failed_at = ?,
                     updated_at = ?
                 WHERE id = ?"
            )->execute([$reason, $now, $now, $payoutId]);

            // Return linked seller_balance_entries rows to available so they can be retried
            if (_bv_sb_table_exists($pdo, 'seller_payout_items')) {
                $stmtItems = $pdo->prepare(
                    'SELECT seller_balance_id FROM seller_payout_items WHERE payout_id = ?'
                );
                $stmtItems->execute([$payoutId]);
                foreach ($stmtItems->fetchAll(PDO::FETCH_COLUMN) ?: [] as $entryId) {
                    $pdo->prepare(
                        "UPDATE seller_balance_entries
                         SET status = 'available', payout_id = NULL, updated_at = ?
                         WHERE id = ? AND status = 'processing'"
                    )->execute([$now, (int) $entryId]);
                }
            }

            bv_seller_balance_commit($pdo);

            $sellerId = (int) ($payout['seller_id'] ?? 0);
            _bvsbe_write_log(
                $pdo, 'payout_failed',
                "Payout {$payoutId} marked failed. Reason: {$reason}",
                ['reason' => $reason],
                $sellerId, $payoutId
            );

            $result['ok'] = true;
        } catch (Throwable $e) {
            bv_seller_balance_rollback($pdo);
            bv_seller_balance_log('payout_failed_error', [
                'payout_id' => $payoutId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// bv_seller_balance_freeze_seller()  /  bv_seller_balance_unfreeze_seller()
//
// Targets seller_balance_entries only. NEVER touches legacy seller_balances.
// ---------------------------------------------------------------------------
if (!function_exists('bv_seller_balance_freeze_seller')) {
    function bv_seller_balance_freeze_seller(
        PDO $pdo,
        int $sellerId,
        string $reason
    ): array {
        $result = ['ok' => false, 'frozen' => 0, 'seller_id' => $sellerId];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries') || $sellerId <= 0) {
            return $result;
        }

        $now = bv_seller_balance_now();

        try {
            $stmt = $pdo->prepare(
                "UPDATE seller_balance_entries
                 SET status = 'frozen', hold_reason = ?, updated_at = ?
                 WHERE seller_id = ? AND status IN ('pending','on_hold','available')"
            );
            $stmt->execute([$reason, $now, $sellerId]);
            $frozen = (int) $stmt->rowCount();

            _bvsbe_write_log(
                $pdo, 'seller_frozen',
                "Seller {$sellerId} frozen. {$frozen} entries. Reason: {$reason}",
                ['reason' => $reason, 'frozen_count' => $frozen],
                $sellerId
            );

            $result['ok']     = true;
            $result['frozen'] = $frozen;
        } catch (Throwable $e) {
            bv_seller_balance_log('freeze_seller_error', [
                'seller_id' => $sellerId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}

if (!function_exists('bv_seller_balance_unfreeze_seller')) {
    function bv_seller_balance_unfreeze_seller(PDO $pdo, int $sellerId): array
    {
        $result = ['ok' => false, 'unfrozen' => 0, 'seller_id' => $sellerId];

        if (!_bv_sb_table_exists($pdo, 'seller_balance_entries') || $sellerId <= 0) {
            return $result;
        }

        $now        = bv_seller_balance_now();
        $newRelease = date('Y-m-d H:i:s', strtotime('+1 day'));

        try {
            $stmt = $pdo->prepare(
                "UPDATE seller_balance_entries
                 SET status = 'on_hold', hold_reason = NULL, release_at = ?, updated_at = ?
                 WHERE seller_id = ? AND status = 'frozen'"
            );
            $stmt->execute([$newRelease, $now, $sellerId]);
            $unfrozen = (int) $stmt->rowCount();

            _bvsbe_write_log(
                $pdo, 'seller_unfrozen',
                "Seller {$sellerId} unfrozen. {$unfrozen} entries returned to on_hold.",
                ['unfrozen_count' => $unfrozen, 'new_release_at' => $newRelease],
                $sellerId
            );

            $result['ok']       = true;
            $result['unfrozen'] = $unfrozen;
        } catch (Throwable $e) {
            bv_seller_balance_log('unfreeze_seller_error', [
                'seller_id' => $sellerId,
                'error'     => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
