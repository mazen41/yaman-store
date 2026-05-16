<?php
/**
 * Reusable database-driven sorting status helpers.
 *
 * The sorting workflow marks real order_items rows with status = 'scanned'.
 * These helpers intentionally derive all badges and order summaries from that
 * persisted value; no UI-only or fake states are used.
 */

if (!defined('SORTING_SORTED_STATUS')) {
    define('SORTING_SORTED_STATUS', 'scanned');
}

function isProductSorted(array $item): bool
{
    return ($item['status'] ?? '') === SORTING_SORTED_STATUS;
}

function getProductSortingStatusMeta(array $item, bool $alreadySortedLabel = false): array
{
    if (isProductSorted($item)) {
        return [
            'key' => 'sorted',
            'label' => $alreadySortedLabel ? 'مفروز مسبقاً' : 'تم الفرز',
            'class' => 'sorting-badge sorting-badge-success',
            'icon' => 'fa-check-circle',
        ];
    }

    return [
        'key' => 'unsorted',
        'label' => 'غير مفروز',
        'class' => 'sorting-badge sorting-badge-pending',
        'icon' => 'fa-clock',
    ];
}

function renderProductSortingBadge(array $item, bool $alreadySortedLabel = false): string
{
    $meta = getProductSortingStatusMeta($item, $alreadySortedLabel);
    return sprintf(
        '<span class="%s"><i class="fas %s"></i> %s</span>',
        htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8')
    );
}

function getOrderSortingSummaryFromItems(array $items): array
{
    $total = 0;
    $sorted = 0;

    foreach ($items as $item) {
        $total++;
        if (isProductSorted($item)) {
            $sorted++;
        }
    }

    $isFullySorted = ($total > 0 && $sorted === $total);

    return [
        'total' => $total,
        'sorted' => $sorted,
        'pending' => max(0, $total - $sorted),
        'is_fully_sorted' => $isFullySorted,
        'status_key' => $isFullySorted ? 'sorted' : 'partial',
        'label' => $isFullySorted ? 'تم الفرز' : 'غير مكتمل الفرز',
    ];
}

function getOrderSortingSummaryFromRow(array $row): array
{
    $total = (int)($row['sorting_total_items'] ?? $row['sort_total_items'] ?? 0);
    $sorted = (int)($row['sorting_sorted_items'] ?? $row['sort_scanned_items'] ?? 0);
    $isFullySorted = ($total > 0 && $sorted === $total);

    return [
        'total' => $total,
        'sorted' => $sorted,
        'pending' => max(0, $total - $sorted),
        'is_fully_sorted' => $isFullySorted,
        'status_key' => $isFullySorted ? 'sorted' : 'partial',
        'label' => $isFullySorted ? 'تم الفرز' : 'غير مكتمل الفرز',
    ];
}

function renderOrderSortingBadge(array $summary): string
{
    $isFullySorted = !empty($summary['is_fully_sorted']);
    $class = $isFullySorted ? 'sorting-badge sorting-badge-success' : 'sorting-badge sorting-badge-pending';
    $icon = $isFullySorted ? 'fa-check-circle' : 'fa-hourglass-half';
    $label = $summary['label'] ?? ($isFullySorted ? 'تم الفرز' : 'قيد الفرز');
    $details = sprintf('(%d/%d)', (int)($summary['sorted'] ?? 0), (int)($summary['total'] ?? 0));

    return sprintf(
        '<span class="%s"><i class="fas %s"></i> %s <small>%s</small></span>',
        htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($details, ENT_QUOTES, 'UTF-8')
    );
}
