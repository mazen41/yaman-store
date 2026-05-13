<?php
/**
 * Status Translation Helper Functions
 * Centralized status translations for the entire system
 */

/**
 * Get Arabic translation for order status
 * @param string $status English status key
 * @return string Arabic translation
 */
function getOrderStatusText($status) {
    $translations = [
        'new' => 'جديد',
        'pending' => 'قيد الانتظار',
        'approved' => 'معتمد',
        'in_preparation' => 'قيد التحضير',
        'processing' => 'قيد المعالجة',
        'shipped' => 'تم الشحن',
        'notes' => 'ملاحظات',
        'under_sorting' => 'قيد الفرز',
        'sorted' => 'تم الفرز',
        'in_delivery' => 'قيد التوصيل',
        'delivered' => 'تم التوصيل',
        'received' => 'تم الاستلام',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
        'rejected' => 'مرفوض',
        'on_hold' => 'معلق',
        'returned' => 'مرتجع',
        'refunded' => 'مسترد',
        'modified' => 'تم التعديل'
    ];
    
    return $translations[$status] ?? $status;
}

/**
 * Get color class for order status badge
 * @param string $status English status key
 * @return string Tailwind CSS classes
 */
function getOrderStatusColor($status) {
    $colors = [
        'new' => 'bg-blue-100 text-blue-800',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'approved' => 'bg-green-100 text-green-800',
        'in_preparation' => 'bg-indigo-100 text-indigo-800',
        'processing' => 'bg-yellow-100 text-yellow-800',
        'shipped' => 'bg-purple-100 text-purple-800',
        'notes' => 'bg-orange-100 text-orange-800',
        'under_sorting' => 'bg-cyan-100 text-cyan-800',
        'sorted' => 'bg-teal-100 text-teal-800',
        'in_delivery' => 'bg-violet-100 text-violet-800',
        'delivered' => 'bg-emerald-100 text-emerald-800',
        'received' => 'bg-lime-100 text-lime-800',
        'completed' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'rejected' => 'bg-red-100 text-red-800',
        'on_hold' => 'bg-gray-100 text-gray-800',
        'returned' => 'bg-amber-100 text-amber-800',
        'refunded' => 'bg-pink-100 text-pink-800',
        'modified' => 'bg-blue-100 text-blue-800'
    ];
    
    return $colors[$status] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Get status badge HTML
 * @param string $status English status key
 * @param string $size Size class (text-xs, text-sm, text-base)
 * @return string HTML badge
 */
function getOrderStatusBadge($status, $size = 'text-xs') {
    $text = getOrderStatusText($status);
    $color = getOrderStatusColor($status);
    
    return '<span class="px-2 inline-flex ' . $size . ' leading-5 font-semibold rounded-full ' . $color . '">' . 
           htmlspecialchars($text) . 
           '</span>';
}

/**
 * Get Arabic translation for invoice status
 * @param string $status English status key
 * @return string Arabic translation
 */
function getInvoiceStatusText($status) {
    $translations = [
        'pending' => 'قيد الانتظار',
        'paid' => 'مدفوعة',
        'partially_paid' => 'مدفوعة جزئياً',
        'overdue' => 'متأخرة',
        'cancelled' => 'ملغية',
        'refunded' => 'مستردة'
    ];
    
    return $translations[$status] ?? $status;
}

/**
 * Get color class for invoice status badge
 * @param string $status English status key
 * @return string Tailwind CSS classes
 */
function getInvoiceStatusColor($status) {
    $colors = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'paid' => 'bg-green-100 text-green-800',
        'partially_paid' => 'bg-blue-100 text-blue-800',
        'overdue' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-gray-100 text-gray-800',
        'refunded' => 'bg-purple-100 text-purple-800'
    ];
    
    return $colors[$status] ?? 'bg-gray-100 text-gray-800';
}

// --- START: FIX ---
// This function is now the one and only version. It correctly uses amounts 
// to determine the status, which fixes the error.
/**
 * Get invoice status badge HTML based on remaining and total amounts
 * @param float $remaining_amount The amount still owed
 * @param float $total_amount The total amount of the invoice
 * @return string HTML badge
 */
function getInvoiceStatusBadge($remaining_amount, $total_amount) {
    // Explicitly cast to float to be safe
    $remaining_amount = (float) $remaining_amount;
    $total_amount = (float) $total_amount;

    if ($remaining_amount <= 0 && $total_amount > 0) {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">مدفوعة بالكامل</span>';
    } elseif ($total_amount > $remaining_amount && $remaining_amount > 0) {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">مدفوعة جزئياً</span>';
    } else {
        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">قيد الانتظار</span>';
    }
}
// --- END: FIX ---


/**
 * Get Arabic translation for payment status
 * @param string $status English status key
 * @return string Arabic translation
 */
function getPaymentStatusText($status) {
    $translations = [
        'pending' => 'قيد الانتظار',
        'completed' => 'مكتمل',
        'failed' => 'فشل',
        'refunded' => 'مسترد',
        'cancelled' => 'ملغي'
    ];
    
    return $translations[$status] ?? $status;
}

function formatOrderNumber($number) {
    if ($number === null || $number === '' || !is_numeric($number)) {
        return '-';
    }

    return 'ORD-' . (int)$number;
}

/**
 * Get Arabic translation for basket/purchase status
 * @param string $status English status key
 * @return string Arabic translation
 */
function getBasketStatusText($status) {
    $translations = [
        'pending' => 'قيد الانتظار',
        'ordered' => 'تم الطلب',
        'received' => 'تم الاستلام',
        'cancelled' => 'ملغي',
        'under_review' => 'قيد المراجعة',
        'reviewed' => 'تمت المراجعة',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض'
    ];
    
    return $translations[$status] ?? $status;
}

/**
 * Get all order statuses as array for dropdowns
 * @return array Associative array [value => label]
 */
function getAllOrderStatuses() {
    return [
        'new' => 'جديد',
        'approved' => 'معتمد',
        'in_preparation' => 'قيد التحضير',
        'shipped' => 'تم الشحن',
        'notes' => 'ملاحظات',
        'under_sorting' => 'قيد الفرز',
        'sorted' => 'تم الفرز',
        'in_delivery' => 'قيد التوصيل',
        'received' => 'تم الاستلام',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
        'modified' => 'تم التعديل'
    ];
}