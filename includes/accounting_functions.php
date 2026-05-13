<?php
// /includes/accounting_functions.php

// Ensure database connection is available
if (!isset($db)) {
    // This file should ideally be included AFTER database.php
    // Or, you can make $db a global or pass it as an argument.
}

/**
 * Retrieves an accounting setting from the database.
 *
 * @param PDO $db The database connection.
 * @param string $key The key of the setting to retrieve.
 * @return string|false The value of the setting, or false if not found.
 */
function get_accounting_setting(PDO $db, string $key) {
    $stmt = $db->prepare("SELECT setting_value FROM accounting_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

/**
 * Creates a journal entry and its items.
 * IMPORTANT: This function now assumes an active transaction is already started in the calling script.
 *
 * @param PDO $db The database connection.
 * @param string $entry_date The date of the journal entry (Y-m-d).
 * @param string $description The description of the journal entry.
 * @param array $items An array of arrays, each containing ['account_id', 'type' => 'debit'/'credit', 'amount'].
 * @param string $source_module The module that created this entry (e.g., 'manual', 'sale', 'purchase', 'transfer').
 * @param int|null $source_id The ID of the record in the source module (e.g., sale_id, purchase_id, transfer_id).
 * @param int $created_by The user ID who created the entry.
 * @return int The ID of the newly created journal entry.
 * @throws Exception If the debits and credits do not balance or a PDO error occurs.
 */
function create_journal_entry(PDO $db, string $entry_date, string $description, array $items, string $source_module, ?int $source_id, int $created_by): int
{
    if (empty($items)) {
        throw new Exception("Journal entry must have at least one item.");
    }

    $total_debit = array_sum(array_column(array_filter($items, fn($item) => $item['type'] === 'debit'), 'amount'));
    $total_credit = array_sum(array_column(array_filter($items, fn($item) => $item['type'] === 'credit'), 'amount'));

    if (abs($total_debit - $total_credit) > 0.01) { // Allow for tiny floating point inaccuracies
        throw new Exception("Journal entry is not balanced. Debits: " . number_format($total_debit, 2) . ", Credits: " . number_format($total_credit, 2));
    }

    // Generate a unique transaction number (e.g., JEV-YYYYMMDD-XXXX)
    $transaction_number_prefix = strtoupper(substr($source_module, 0, 3));
    $transaction_number = $transaction_number_prefix . '-' . date('Ymd') . '-' . uniqid();

    // *** REMOVED TRANSACTION LOGIC: Rely on the calling script's transaction ***

    try {
        // 1. Insert into journal_entries
        $stmt_entry = $db->prepare(
            "INSERT INTO journal_entries (entry_date, description, source_module, source_id, created_by, transaction_number)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt_entry->execute([$entry_date, $description, $source_module, $source_id, $created_by, $transaction_number]);
        $entry_id = $db->lastInsertId();

        // 2. Insert into journal_entry_items
        $stmt_item = $db->prepare(
            "INSERT INTO journal_entry_items (entry_id, account_id, type, amount)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            if ($item['amount'] > 0) { // Only insert if amount is positive
                $stmt_item->execute([$entry_id, $item['account_id'], $item['type'], $item['amount']]);
            }
        }

        // *** REMOVED TRANSACTION LOGIC ***
        return $entry_id;

    } catch (PDOException $e) {
        // If an error occurs, the outer catch block in the main script will handle the rollback.
        throw new Exception("Database error during journal entry creation: " . $e->getMessage());
    }
}

/**
 * Deletes journal entries and their associated items based on source module and ID.
 * IMPORTANT: This function now assumes an active transaction is already started in the calling script.
 *
 * @param PDO $db The database connection.
 * @param string $source_module The module that created the entry (e.g., 'sale', 'purchase').
 * @param int $source_id The ID of the record in the source module.
 * @return bool True if entries were deleted, false otherwise (e.g., no entries found or error).
 * @throws Exception on PDO error.
 */
function delete_journal_entry_by_source(PDO $db, string $source_module, int $source_id): bool {
    if (empty($source_module) || empty($source_id)) {
        error_log("Attempted to delete journal entry with empty source_module or source_id.");
        return false;
    }

    // *** REMOVED TRANSACTION LOGIC: Rely on the calling script's transaction ***

    try {
        // First, get the entry IDs to delete their items
        $stmt_get_ids = $db->prepare("SELECT id FROM journal_entries WHERE source_module = ? AND source_id = ?");
        $stmt_get_ids->execute([$source_module, $source_id]);
        $entry_ids = $stmt_get_ids->fetchAll(PDO::FETCH_COLUMN);

        if (empty($entry_ids)) {
            return false;
        }

        // Delete items related to these entries
        $placeholders = implode(',', array_fill(0, count($entry_ids), '?'));
        $stmt_delete_items = $db->prepare("DELETE FROM journal_entry_items WHERE entry_id IN ($placeholders)");
        $stmt_delete_items->execute($entry_ids);

        // Delete the journal entries themselves
        $stmt_delete_entries = $db->prepare("DELETE FROM journal_entries WHERE source_module = ? AND source_id = ?");
        $stmt_delete_entries->execute([$source_module, $source_id]);

        return $stmt_delete_entries->rowCount() > 0; // Return true if at least one entry was deleted
    } catch (PDOException $e) {
        // If an error occurs, the outer catch block in the main script will handle the rollback.
        error_log("Failed to delete journal entry for $source_module #$source_id: " . $e->getMessage());
        throw new Exception("Database error during journal entry deletion: " . $e->getMessage());
    }
}

/**
 * Retrieves the account type for a given account ID.
 *
 * @param PDO $db The database connection.
 * @param int $account_id The ID of the account.
 * @return string|null The type of the account (e.g., 'asset', 'liability', 'equity', 'revenue', 'expense'), or null if not found.
 */
function get_account_type(PDO $db, int $account_id): ?string {
    $stmt = $db->prepare("SELECT type FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    return $stmt->fetchColumn();
}