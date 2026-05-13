/**
 * Purchase Basket Complete System (ENHANCED & FIXED)
 * EDITED: Logic updated to handle 'purchase_cards' instead of 'loyalty_cards'.
 * FIXED: Number formatting updated to use two decimal places.
 */

console.log('🚀 Basket Complete JS Loaded (v2.2 - Purchase Cards & Formatting Fix)');

let selectedCustomer = null;
let selectedOrders = new Map();
let searchTimeout = null;
let allOrders = [];

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ DOM Content Loaded');

    const paymentSourceType = document.getElementById('paymentSourceType');
    const bankAccountSelect = document.getElementById('bankAccountSelect');
    const purchaseCardSelect = document.getElementById('purchaseCardSelect'); // Changed from loyaltyCardSelect
    
    // --- EVENT LISTENERS ---
    initializeCustomerSearch();
    initializeFormValidation();
    
    document.getElementById('shippingCost').addEventListener('input', updateTotals);
    document.getElementById('taxRate').addEventListener('input', updateTotals);
    document.getElementById('taxIncluded').addEventListener('change', updateTotals);
    document.getElementById('subtotalOverride').addEventListener('input', updateTotals);

    // --- START OF FIX: Add event listener to track manual edits on total products input ---
    const totalProductsInput = document.getElementById('totalProductsInput');
    if (totalProductsInput) {
        totalProductsInput.addEventListener('input', function() {
            // Set a data attribute to flag that the user has manually changed this value.
            this.dataset.userModified = 'true';
        });
    }
    // --- END OF FIX ---

    if (paymentSourceType) {
        paymentSourceType.addEventListener('change', handlePaymentSourceChange);
    }
    if (bankAccountSelect) {
        bankAccountSelect.addEventListener('change', updateSourceBalance);
    }
    if (purchaseCardSelect) { // Changed from loyaltyCardSelect
        purchaseCardSelect.addEventListener('change', updateSourceBalance);
    }

    console.log('✅ Initialization Complete');
});


// ============================================
// CUSTOMER SEARCH & SELECTION (No changes needed)
// ============================================

function initializeCustomerSearch() {
    const searchInput = document.getElementById('customerSearch');
    const resultsDiv = document.getElementById('customerResults');
    if (!searchInput) return;
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) { resultsDiv.style.display = 'none'; return; }
        searchTimeout = setTimeout(() => searchCustomers(query), 300);
    });
    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
}
async function searchCustomers(query) { /* ... Your existing code is correct ... */
    const resultsDiv = document.getElementById('customerResults');
    try {
        resultsDiv.innerHTML = '<div class="search-result-item"><i class="fas fa-spinner fa-spin"></i> جاري البحث...</div>';
        resultsDiv.style.display = 'block';
        const response = await fetch(`api/search_customers.php?q=${encodeURIComponent(query)}&limit=10`);
        const customers = await response.json();
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="search-result-item" style="text-align: center; color: #6b7280;"><i class="fas fa-info-circle"></i> لا توجد نتائج</div>';
            return;
        }
        let html = '';
        customers.forEach(customer => {
            html += `<div class="search-result-item" onclick="selectCustomer(${customer.id}, '${escapeHtml(customer.name)}', '${escapeHtml(customer.customer_code)}')"><div class="result-name"><i class="fas fa-user text-blue-600"></i> ${escapeHtml(customer.name)}<span style="float: left; font-size: 12px; color: #6b7280;">${escapeHtml(customer.customer_code)}</span></div><div class="result-details">${customer.mobile_formatted ? `<span><i class="fas fa-phone"></i> ${customer.mobile_formatted}</span>` : ''}${customer.city ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(customer.city)}</span>` : ''}</div></div>`;
        });
        resultsDiv.innerHTML = html;
    } catch (error) {
        console.error('Search error:', error);
        resultsDiv.innerHTML = '<div class="search-result-item" style="color: #ef4444;"><i class="fas fa-exclamation-triangle"></i> حدث خطأ في البحث</div>';
    }
}
function selectCustomer(customerId, customerName, customerCode) { /* ... Your existing code is correct ... */
    selectedCustomer = { id: customerId, name: customerName, code: customerCode };
    document.getElementById('selectedCustomerId').value = customerId;
    document.getElementById('selectedCustomerDisplay').value = `${customerName} (${customerCode})`;
    document.getElementById('customerSearch').value = '';
    document.getElementById('customerResults').style.display = 'none';
    loadCustomerOrders(customerId);
    loadCustomerInvoices(customerId, customerName);
}


// ============================================
// LOAD CUSTOMER ORDERS & INVOICES (No changes needed)
// ============================================

async function loadCustomerOrders(customerId) { /* ... Your existing code is correct ... */
    const ordersSection = document.getElementById('ordersSection');
    const ordersList = document.getElementById('ordersList');
    try {
        ordersList.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p class="mt-4">جاري تحميل الطلبات...</p></div>';
        ordersSection.style.display = 'block';
        const response = await fetch(`api/get_customer_orders.php?customer_id=${customerId}`);
        allOrders = await response.json();
        if (allOrders.length === 0) {
            ordersList.innerHTML = `<div class="col-span-full text-center py-12" style="grid-column: 1 / -1;"><i class="fas fa-inbox" style="font-size: 48px; color: #d1d5db;"></i><p style="margin-top: 16px; color: #6b7280;">لا توجد طلبات متاحة لهذا العميل</p></div>`;
            return;
        }
        displayOrders(allOrders);
    } catch (error) {
        console.error('Load orders error:', error);
        ordersList.innerHTML = `<div class="col-span-full text-center py-12" style="grid-column: 1 / -1;"><i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #ef4444;"></i><p style="margin-top: 16px; color: #ef4444;">فشل تحميل الطلبات</p></div>`;
    }
}
function displayOrders(orders) { /* ... Your existing code is correct ... */ 
    const ordersList = document.getElementById('ordersList');
    let html = '';
    orders.forEach(order => {
        const isSelected = selectedOrders.has(order.id);
        const isInBasket = order.in_basket;
        const isCompleted = order.status === 'completed' || order.status === 'cancelled';
        const isDisabled = isInBasket || isCompleted;
        let statusBadge = '';
        if (isInBasket) { statusBadge = `<div class="order-badge badge-warning"><i class="fas fa-lock"></i> في سلة ${order.basket_code || ''}</div>`; } 
        else if (isCompleted) { statusBadge = `<div class="order-badge badge-danger"><i class="fas fa-times-circle"></i> ${order.status === 'completed' ? 'مكتمل' : 'ملغي'}</div>`; } 
        else if (isSelected) { statusBadge = `<div class="order-badge badge-success"><i class="fas fa-check"></i> محدد</div>`; }
        html += `<div class="order-card ${isSelected ? 'selected' : ''} ${isDisabled ? 'disabled' : ''}" id="order-${order.id}" onclick="${isDisabled ? '' : `toggleOrderSelection(${order.id})`}">${statusBadge}<div style="margin-bottom: 12px;"><div style="font-weight: 700; font-size: 16px; color: #1f2937; margin-bottom: 4px;"><i class="fas fa-receipt text-purple-600"></i> ${escapeHtml(order.order_number)}</div><div style="font-size: 13px; color: #6b7280;"><i class="far fa-calendar"></i> ${order.created_at}</div></div><div style="background: #f9fafb; padding: 12px; border-radius: 8px; margin-bottom: 12px;"><div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 14px;"><span style="color: #6b7280;">قبل الخصم:</span><span style="font-weight: 600;">${formatMoney(order.subtotal_amount)}</span></div>${order.discount_amount > 0 ? `<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 14px;"><span style="color: #6b7280;">الخصم:</span><span style="font-weight: 600; color: #ef4444;">-${formatMoney(order.discount_amount)}</span></div>` : ''}<div style="display: flex; justify-content: space-between; font-size: 16px; padding-top: 6px; border-top: 1px solid #e5e7eb;"><span style="color: #1f2937; font-weight: 600;">المبلغ النهائي:</span><span style="font-weight: 700; color: #C7A46D;">${formatMoney(order.final_amount)}</span></div></div>${!isDisabled ? `<button type="button" class="btn btn-primary" style="width: 100%; padding: 8px; font-size: 14px;" onclick="event.stopPropagation(); viewOrderDetails(${order.id})"><i class="fas fa-eye"></i> عرض التفاصيل</button>` : ''}</div>`;
    });
    ordersList.innerHTML = html;
}
async function loadCustomerInvoices(customerId, customerName) { /* ... Your existing code is correct ... */
    const latestInvoicesCard = document.getElementById('latestInvoicesCard');
    const customerInvoicesCard = document.getElementById('customerInvoicesCard');
    const title = document.getElementById('customerInvoicesTitle');
    const tableBody = document.getElementById('customerInvoicesTableBody');
    title.innerHTML = `<i class="fas fa-user-tag text-blue-600"></i> فواتير ${escapeHtml(customerName)}`;
    tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> جاري تحميل الفواتير...</td></tr>';
    latestInvoicesCard.style.display = 'none';
    customerInvoicesCard.style.display = 'block';
    try {
        const response = await fetch(`api/get_customer_invoices.php?customer_id=${customerId}`);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const invoices = await response.json();
        if (invoices.error) throw new Error(invoices.error);
        if (invoices.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-info-circle"></i> لا توجد فواتير مسجلة لهذا العميل.</td></tr>';
            return;
        }
        let html = '';
        invoices.forEach(invoice => {
            const statusClass = `invoice-status-${invoice.status.replace('_', '-')}`;
            html += `<tr><td><strong>${escapeHtml(invoice.invoice_number)}</strong></td><td>${formatMoney(invoice.total_amount)}</td><td class="text-amber-600">${formatMoney(invoice.paid_amount)}</td><td class="text-red-600 font-weight-bold">${formatMoney(invoice.remaining_amount)}</td><td><span class="invoice-status ${statusClass}">${escapeHtml(invoice.status)}</span></td><td>${escapeHtml(invoice.due_date_formatted)}</td><td>${escapeHtml(invoice.created_at_formatted)}</td></tr>`;
        });
        tableBody.innerHTML = html;
    } catch (error) {
        console.error('Failed to load customer invoices:', error);
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-red-500"><i class="fas fa-exclamation-triangle"></i> فشل في تحميل الفواتير.</td></tr>';
    }
}

// ============================================
// ORDER SELECTION (No changes needed)
// ============================================

async function toggleOrderSelection(orderId) { /* ... Your existing code is correct ... */
    if (selectedOrders.has(orderId)) {
        selectedOrders.delete(orderId);
        document.getElementById(`order-${orderId}`).classList.remove('selected');
        updateBasketDisplay();
    } else {
        try {
            const response = await fetch(`api/check_order_basket.php?order_id=${orderId}`);
            const result = await response.json();
            if (result.in_basket) {
                alert(`⚠️ تحذير: هذا الطلب موجود بالفعل في السلة "${result.basket_name}" (${result.basket_code})\n\nلا يمكن إضافة نفس الطلب في أكثر من سلة.`);
                return;
            }
            const response2 = await fetch(`api/get_order_details.php?order_id=${orderId}`);
            const order = await response2.json();
            selectedOrders.set(orderId, order);
            document.getElementById(`order-${orderId}`).classList.add('selected');
            updateBasketDisplay();
        } catch (error) {
            console.error('Error checking order:', error);
            alert('حدث خطأ أثناء التحقق من الطلب');
        }
    }
}
function removeOrderFromBasket(orderId) { /* ... Your existing code is correct ... */
    if (confirm('هل أنت متأكد من إزالة هذا الطلب من السلة؟')) {
        selectedOrders.delete(orderId);
        const orderCard = document.getElementById(`order-${orderId}`);
        if (orderCard) orderCard.classList.remove('selected');
        updateBasketDisplay();
    }
}


// ============================================
// BASKET DISPLAY (No changes needed)
// ============================================

function updateBasketDisplay() { /* ... Your existing code is correct ... */
    const emptyBasket = document.getElementById('emptyBasket');
    const basketContent = document.getElementById('basketContent');
    const basketBody = document.getElementById('basketTableBody');
    const itemsCount = document.getElementById('itemsCount');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const lockBasketBtn = document.getElementById('lockBasketBtn');
    if (selectedOrders.size === 0) {
        emptyBasket.style.display = 'block';
        basketContent.style.display = 'none';
        itemsCount.textContent = '0 طلب';
        itemsCount.className = 'badge-info';
        saveDraftBtn.disabled = true;
        lockBasketBtn.disabled = true;
        updateTotals();
        return;
    }
    emptyBasket.style.display = 'none';
    basketContent.style.display = 'block';
    itemsCount.textContent = `${selectedOrders.size} طلب`;
    itemsCount.className = 'badge-success';
    saveDraftBtn.disabled = false;
    lockBasketBtn.disabled = false;
    let html = '';
    let rowNum = 1;
    selectedOrders.forEach((order, orderId) => {
        html += `<tr><td style="text-align: center; font-weight: 600;">${rowNum++}</td><td style="font-weight: 600; color: #1f2937;">${escapeHtml(order.order_number)}</td><td>${escapeHtml(order.customer_name)}</td><td>${order.created_at}</td><td style="text-align: left; font-family: monospace;">${formatMoney(order.subtotal_amount)}</td><td style="text-align: left; font-family: monospace; color: #ef4444;">${formatMoney(order.discount_amount)}</td><td style="text-align: left; font-family: monospace; font-weight: 700; color: #C7A46D;">${formatMoney(order.final_amount)}</td><td><input type="text" class="form-control" style="padding: 6px 10px; font-size: 13px;" placeholder="ملاحظات اختيارية" onchange="updateOrderNotes(${orderId}, this.value)" value="${escapeHtml(order.notes || '')}"></td><td style="text-align: center;"><button type="button" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;" onclick="removeOrderFromBasket(${orderId})" title="إزالة"><i class="fas fa-trash"></i></button></td></tr>`;
    });
    basketBody.innerHTML = html;
    updateTotals();
}
function updateOrderNotes(orderId, notes) { /* ... Your existing code is correct ... */
    const order = selectedOrders.get(orderId);
    if (order) order.notes = notes;
}

// ============================================
// PAYMENT SOURCE UI
// ============================================

function handlePaymentSourceChange() {
    const selectedType = document.getElementById('paymentSourceType').value;
    const detailsContainer = document.getElementById('paymentSourceDetails');
    const bankSelector = document.getElementById('bankAccountSelector');
    const cardSelector = document.getElementById('purchaseCardSelector'); // Changed from loyaltyCardSelector
    const balanceDisplay = document.getElementById('sourceBalanceDisplay');
    
    detailsContainer.style.display = 'none';
    bankSelector.style.display = 'none';
    cardSelector.style.display = 'none';
    balanceDisplay.textContent = '';
    
    document.getElementById('bankAccountSelect').value = '';
    document.getElementById('purchaseCardSelect').value = ''; // Changed from loyaltyCardSelect
    
    if (selectedType === 'bank_account') {
        detailsContainer.style.display = 'block';
        bankSelector.style.display = 'block';
    } else if (selectedType === 'purchase_card') { // Changed from loyalty_card
        detailsContainer.style.display = 'block';
        cardSelector.style.display = 'block';
    }
}

function updateSourceBalance(event) {
    const selectElement = event.target;
    const balanceDisplay = document.getElementById('sourceBalanceDisplay');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const balance = selectedOption.getAttribute('data-balance');
    
    if (balance) {
        balanceDisplay.textContent = `الرصيد المتاح: ${formatMoney(balance)}`;
        const grandTotalText = document.getElementById('grandTotalDisplay').textContent;
        const grandTotal = parseFloat(grandTotalText.replace(/[^0-9.-]+/g,""));
        
        // Check specifically for purchase_card type
        if (document.getElementById('paymentSourceType').value === 'purchase_card' && parseFloat(balance) < grandTotal) {
             balanceDisplay.innerHTML += ' <span style="color: #ef4444;">(غير كافٍ)</span>';
        }
    } else {
        balanceDisplay.textContent = '';
    }
}

// ============================================
// TOTALS CALCULATION
// ============================================
function updateTotals() {
    // --- START OF FIX: This entire function is replaced ---
    const totalProductsInput = document.getElementById('totalProductsInput');
    let subtotal = 0;
    let totalDiscount = 0;
    selectedOrders.forEach(order => {
        subtotal += parseFloat(order.subtotal_amount || 0);
        totalDiscount += parseFloat(order.discount_amount || 0);
    });

    if (selectedOrders.size > 0) {
        // Only update automatically if the user has NOT manually edited the field.
        if (!totalProductsInput.dataset.userModified) {
            const orderIds = Array.from(selectedOrders.keys());
            const formData = new FormData();
            orderIds.forEach(id => formData.append('order_ids[]', id));

            fetch('api/get_order_details.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                // Double-check again before setting the value.
                if (!totalProductsInput.dataset.userModified) {
                    if (data && data.total_products !== undefined) {
                        totalProductsInput.value = data.total_products;
                    } else {
                        totalProductsInput.value = 0;
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching total products:', error);
                if (!totalProductsInput.dataset.userModified) {
                    totalProductsInput.value = 'Error';
                }
            });
        }
    } else {
        // If the basket is empty, reset the field and the manual edit flag.
        totalProductsInput.value = 0;
        delete totalProductsInput.dataset.userModified;
    }

    const subtotalOverride = parseFloat(document.getElementById('subtotalOverride').value);
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
    const taxIncluded = document.getElementById('taxIncluded').checked;
    const baseForCalculation = !isNaN(subtotalOverride) ? subtotalOverride : (subtotal - totalDiscount);
    let taxAmount = 0;
    let grandTotal = 0;

    if (taxIncluded) {
        taxAmount = (baseForCalculation * taxRate) / (100 + taxRate);
        grandTotal = baseForCalculation + shippingCost;
    } else {
        taxAmount = (baseForCalculation * taxRate) / 100;
        grandTotal = baseForCalculation + taxAmount + shippingCost;
    }

    document.getElementById('totalOrders').textContent = selectedOrders.size;
    document.getElementById('subtotalDisplay').textContent = formatMoney(subtotal);
    document.getElementById('discountDisplay').textContent = formatMoney(totalDiscount);
    document.getElementById('taxAmountDisplay').textContent = formatMoney(taxAmount);
    document.getElementById('grandTotalDisplay').textContent = formatMoney(grandTotal);
    // --- END OF FIX ---
}

// ============================================
// ORDER DETAILS MODAL (No changes needed)
// ============================================

async function viewOrderDetails(orderId) { /* ... Your existing code is correct ... */ 
    try {
        const response = await fetch(`api/get_order_details.php?order_id=${orderId}`);
        const order = await response.json();
        let itemsHtml = '';
        if (order.items && order.items.length > 0) {
            itemsHtml = `<div style="margin-top: 20px;"><h4 style="font-weight: 600; margin-bottom: 12px;"><i class="fas fa-box text-blue-600"></i> المنتجات</h4><table style="width: 100%; border-collapse: collapse;"><thead><tr style="background: #f9fafb;"><th style="padding: 10px; text-align: right; border-bottom: 2px solid #e5e7eb;">المنتج</th><th style="padding: 10px; text-align: center; border-bottom: 2px solid #e5e7eb;">الكمية</th><th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb;">السعر</th><th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb;">الإجمالي</th></tr></thead><tbody>${order.items.map(item => `<tr><td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">${escapeHtml(item.product_name || item.product_code)}</td><td style="padding: 10px; text-align: center; border-bottom: 1px solid #e5e7eb;">${item.quantity}</td><td style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">${formatMoney(item.unit_price)}</td><td style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; font-weight: 600;">${formatMoney(item.total_price)}</td></tr>`).join('')}</tbody></table></div>`;
        }
        const modal = `<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;" onclick="this.remove()"><div style="background: white; border-radius: 16px; max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto;" onclick="event.stopPropagation()"><div style="padding: 24px; border-bottom: 2px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: #f9fafb;"><h3 style="font-size: 20px; font-weight: 700;"><i class="fas fa-file-invoice text-purple-600"></i> تفاصيل الطلب: ${escapeHtml(order.order_number)}</h3><button onclick="this.closest('[style*=fixed]').remove()" style="background: #ef4444; color: white; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer;"><i class="fas fa-times"></i></button></div><div style="padding: 24px;"><div style="background: #f0fdf4; padding: 16px; border-radius: 8px; margin-bottom: 20px;"><div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;"><div><strong>العميل:</strong> ${escapeHtml(order.customer_name)}</div><div><strong>الكود:</strong> ${escapeHtml(order.customer_code)}</div><div><strong>التاريخ:</strong> ${order.created_at}</div><div><strong>الحالة:</strong> ${getStatusBadge(order.status)}</div></div></div>${itemsHtml}<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: white; margin-top: 20px;"><div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.2);"><span>المبلغ قبل الخصم:</span><strong>${formatMoney(order.subtotal_amount)}</strong></div><div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.2);"><span>الخصم:</span><strong>-${formatMoney(order.discount_amount)}</strong></div><div style="display: flex; justify-content: space-between; padding: 10px 0; padding-top: 16px; border-top: 2px solid rgba(255,255,255,0.3); font-size: 20px; font-weight: 700;"><span>المبلغ النهائي:</span><strong>${formatMoney(order.final_amount)}</strong></div></div>${order.notes ? `<div style="margin-top: 20px; padding: 16px; background: #fef3c7; border-radius: 8px;"><strong><i class="fas fa-sticky-note"></i> ملاحظات:</strong><p style="margin-top: 8px;">${escapeHtml(order.notes)}</p></div>` : ''}</div></div></div>`;
        document.body.insertAdjacentHTML('beforeend', modal);
    } catch (error) {
        console.error('Error loading order details:', error);
        alert('فشل تحميل تفاصيل الطلب');
    }
}

// ============================================
// FORM VALIDATION (No changes needed)
// ============================================

function initializeFormValidation() { /* ... Your existing code is correct ... */ 
    const form = document.getElementById('basketForm');
    form.addEventListener('submit', function(e) {
        if (selectedOrders.size === 0) {
            e.preventDefault();
            alert('⚠️ يرجى اختيار طلب واحد على الأقل');
            return false;
        }
        const ordersData = [];
        selectedOrders.forEach((order, orderId) => {
            ordersData.push({ id: orderId, notes: order.notes || '' });
        });
        document.getElementById('selectedOrdersInput').value = JSON.stringify(ordersData);
        return true;
    });
}

// ============================================
// UTILITY FUNCTIONS (UPDATED)
// ============================================

function formatMoney(amount) { 
    if (amount === null || amount === undefined) return '0.00 ر.س';
    // FIX: Changed toFixed(3) to toFixed(2) to show two decimal places.
    return parseFloat(amount).toFixed(2) + ' ر.س';
}
function escapeHtml(text) { /* ... Your existing code is correct ... */
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function getStatusBadge(status) { /* ... Your existing code is correct ... */
    const badges = {'new': '<span class="badge-info">جديد</span>','processing': '<span class="badge-warning">قيد المعالجة</span>','completed': '<span class="badge-success">مكتمل</span>','cancelled': '<span class="badge-danger">ملغي</span>','in_basket': '<span class="badge-primary">في سلة</span>'};
    return badges[status] || status;
}