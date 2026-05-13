/**
 * Purchase Basket - API-Based System
 * Modern autocomplete search with debouncing
 */

let selectedCustomer = null;
let selectedOrders = new Map();
let searchTimeout = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeSearch();
    initializeShippingCost();
});

// ============================================
// CUSTOMER SEARCH WITH AUTOCOMPLETE
// ============================================

function initializeSearch() {
    const searchInput = document.getElementById('customer_search');
    const resultsDiv = document.getElementById('customerResults');
    
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Clear previous timeout
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            resultsDiv.innerHTML = '';
            resultsDiv.style.display = 'none';
            return;
        }
        
        // Debounce search (wait 300ms after user stops typing)
        searchTimeout = setTimeout(() => {
            searchCustomers(query);
        }, 300);
    });
    
    // Close results when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
}

async function searchCustomers(query) {
    const resultsDiv = document.getElementById('customerResults');
    
    try {
        resultsDiv.innerHTML = '<div class="search-loading"><i class="fas fa-spinner fa-spin"></i> جاري البحث...</div>';
        resultsDiv.style.display = 'block';
        
        const response = await fetch(`api/search_customers.php?q=${encodeURIComponent(query)}&limit=10`);
        
        if (!response.ok) {
            throw new Error('فشل البحث');
        }
        
        const customers = await response.json();
        
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="search-no-results"><i class="fas fa-info-circle"></i> لا توجد نتائج</div>';
            return;
        }
        
        // Display results
        let html = '<div class="search-results-list">';
        customers.forEach(customer => {
            html += `
                <div class="search-result-item" onclick="selectCustomer(${customer.id}, '${escapeHtml(customer.name)}')">
                    <div class="result-main">
                        <div class="result-name">
                            <i class="fas fa-user text-blue-600"></i>
                            ${escapeHtml(customer.name)}
                        </div>
                        <div class="result-code">${escapeHtml(customer.customer_code)}</div>
                    </div>
                    <div class="result-details">
                        ${customer.mobile_formatted ? `<span><i class="fas fa-phone"></i> ${customer.mobile_formatted}</span>` : ''}
                        ${customer.city ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(customer.city)}</span>` : ''}
                    </div>
                </div>
            `;
        });
        html += '</div>';
        
        resultsDiv.innerHTML = html;
        
    } catch (error) {
        console.error('Search error:', error);
        resultsDiv.innerHTML = `<div class="search-error"><i class="fas fa-exclamation-triangle"></i> ${error.message}</div>`;
    }
}

function selectCustomer(customerId, customerName) {
    selectedCustomer = { id: customerId, name: customerName };
    
    // Update UI
    document.getElementById('customer_id').value = customerId;
    document.getElementById('customer_name_display').value = customerName;
    document.getElementById('customer_search').value = '';
    document.getElementById('customerResults').style.display = 'none';
    
    // Load customer orders
    loadCustomerOrders(customerId);
}

// ============================================
// LOAD CUSTOMER ORDERS
// ============================================

async function loadCustomerOrders(customerId) {
    const ordersContainer = document.getElementById('ordersContainer');
    const ordersList = document.getElementById('ordersList');
    
    try {
        ordersList.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-3x text-purple-600"></i><p class="mt-4 text-lg">جاري تحميل الطلبات...</p></div>';
        ordersContainer.style.display = 'block';
        
        const response = await fetch(`api/get_customer_orders.php?customer_id=${customerId}`);
        
        if (!response.ok) {
            throw new Error('فشل تحميل الطلبات');
        }
        
        const orders = await response.json();
        
        if (orders.length === 0) {
            ordersList.innerHTML = `
                <div class="col-span-full text-center py-12">
                    <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                    <p class="text-xl text-gray-500">لا توجد طلبات لهذا العميل</p>
                </div>
            `;
            return;
        }
        
        // Display orders
        let html = '';
        orders.forEach(order => {
            const isSelected = selectedOrders.has(order.id);
            const isInBasket = order.in_basket;
            
            html += `
                <div class="order-card ${isSelected ? 'selected' : ''} ${isInBasket ? 'disabled' : ''}" 
                     id="order-${order.id}"
                     onclick="${isInBasket ? '' : `toggleOrderSelection(${order.id})`}">
                    
                    ${isInBasket ? '<div class="order-badge badge-warning"><i class="fas fa-lock"></i> في سلة</div>' : ''}
                    ${isSelected ? '<div class="order-badge badge-success"><i class="fas fa-check"></i> محدد</div>' : ''}
                    
                    <div class="order-header">
                        <div class="order-number">
                            <i class="fas fa-receipt"></i>
                            ${escapeHtml(order.order_number)}
                        </div>
                        <div class="order-date">
                            <i class="far fa-calendar"></i>
                            ${order.created_at}
                        </div>
                    </div>
                    
                    <div class="order-amounts">
                        <div class="amount-row">
                            <span>المبلغ قبل الخصم:</span>
                            <span class="amount">${formatMoney(order.subtotal_amount)}</span>
                        </div>
                        ${order.discount_amount > 0 ? `
                        <div class="amount-row discount">
                            <span>الخصم:</span>
                            <span class="amount">-${formatMoney(order.discount_amount)}</span>
                        </div>
                        ` : ''}
                        <div class="amount-row total">
                            <span>المبلغ النهائي:</span>
                            <span class="amount">${formatMoney(order.final_amount)}</span>
                        </div>
                    </div>
                    
                    <div class="order-actions">
                        <button type="button" class="btn-view" onclick="event.stopPropagation(); viewOrderDetails(${order.id})">
                            <i class="fas fa-eye"></i>
                            عرض التفاصيل
                        </button>
                    </div>
                </div>
            `;
        });
        
        ordersList.innerHTML = html;
        
    } catch (error) {
        console.error('Load orders error:', error);
        ordersList.innerHTML = `
            <div class="col-span-full text-center py-12">
                <i class="fas fa-exclamation-triangle text-6xl text-red-300 mb-4"></i>
                <p class="text-xl text-red-500">${error.message}</p>
            </div>
        `;
    }
}

// ============================================
// ORDER SELECTION
// ============================================

function toggleOrderSelection(orderId) {
    const orderCard = document.getElementById(`order-${orderId}`);
    
    if (selectedOrders.has(orderId)) {
        // Deselect
        selectedOrders.delete(orderId);
        orderCard.classList.remove('selected');
    } else {
        // Select - get order data from API
        fetch(`api/get_order_details.php?order_id=${orderId}`)
            .then(response => response.json())
            .then(order => {
                selectedOrders.set(orderId, order);
                orderCard.classList.add('selected');
                updateBasketDisplay();
            })
            .catch(error => {
                console.error('Error loading order:', error);
                alert('فشل تحميل بيانات الطلب');
            });
    }
    
    updateBasketDisplay();
}

function removeOrderFromBasket(orderId) {
    selectedOrders.delete(orderId);
    
    const orderCard = document.getElementById(`order-${orderId}`);
    if (orderCard) {
        orderCard.classList.remove('selected');
    }
    
    updateBasketDisplay();
}

// ============================================
// BASKET DISPLAY
// ============================================

function updateBasketDisplay() {
    const basketContainer = document.getElementById('basketItemsContainer');
    const basketTable = document.getElementById('basketItemsTable');
    const basketBody = document.getElementById('basketItemsBody');
    const totalsBox = document.getElementById('totalsBox');
    const itemsCount = document.getElementById('itemsCount');
    const saveBtn = document.getElementById('saveBasketBtn');
    
    if (selectedOrders.size === 0) {
        basketContainer.style.display = 'block';
        basketTable.style.display = 'none';
        totalsBox.style.display = 'none';
        itemsCount.textContent = '0 طلب';
        saveBtn.disabled = true;
        return;
    }
    
    // Show table
    basketContainer.style.display = 'none';
    basketTable.style.display = 'table';
    totalsBox.style.display = 'block';
    itemsCount.textContent = `${selectedOrders.size} طلب`;
    saveBtn.disabled = false;
    
    // Build table rows
    let html = '';
    let rowNum = 1;
    let subtotal = 0;
    let totalDiscount = 0;
    
    selectedOrders.forEach((order, orderId) => {
        subtotal += parseFloat(order.subtotal_amount || 0);
        totalDiscount += parseFloat(order.discount_amount || 0);
        
        html += `
            <tr>
                <td class="text-center">${rowNum++}</td>
                <td><strong>${escapeHtml(order.order_number)}</strong></td>
                <td>${escapeHtml(order.customer_name)}</td>
                <td>${order.created_at}</td>
                <td class="text-left">${formatMoney(order.subtotal_amount)}</td>
                <td class="text-left text-red-600">${formatMoney(order.discount_amount)}</td>
                <td class="text-left font-bold">${formatMoney(order.final_amount)}</td>
                <td class="text-center">
                    <button type="button" class="btn-remove" onclick="removeOrderFromBasket(${orderId})" title="إزالة">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    basketBody.innerHTML = html;
    
    // Update totals
    const shippingCost = parseFloat(document.getElementById('shipping_cost').value || 0);
    const grandTotal = subtotal - totalDiscount + shippingCost;
    
    document.getElementById('subtotal').textContent = formatMoney(subtotal);
    document.getElementById('totalDiscount').textContent = formatMoney(totalDiscount);
    document.getElementById('shippingCostDisplay').textContent = formatMoney(shippingCost);
    document.getElementById('grandTotal').textContent = formatMoney(grandTotal);
    
    // Update hidden input for form submission
    document.getElementById('selectedOrdersInput').value = JSON.stringify(Array.from(selectedOrders.keys()));
}

// ============================================
// ORDER DETAILS MODAL
// ============================================

async function viewOrderDetails(orderId) {
    const panel = document.getElementById('orderDetailsPanel');
    
    try {
        panel.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        panel.style.display = 'block';
        
        const response = await fetch(`api/get_order_details.php?order_id=${orderId}`);
        const order = await response.json();
        
        let html = `
            <div class="details-header">
                <h3><i class="fas fa-file-invoice"></i> تفاصيل الطلب: ${escapeHtml(order.order_number)}</h3>
                <button type="button" onclick="closeOrderDetails()" class="btn-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="details-body">
                <div class="details-section">
                    <h4><i class="fas fa-user"></i> معلومات العميل</h4>
                    <div class="details-grid">
                        <div><strong>الاسم:</strong> ${escapeHtml(order.customer_name)}</div>
                        <div><strong>الكود:</strong> ${escapeHtml(order.customer_code)}</div>
                        ${order.mobile_number ? `<div><strong>الجوال:</strong> ${order.mobile_number}</div>` : ''}
                    </div>
                </div>
                
                <div class="details-section">
                    <h4><i class="fas fa-info-circle"></i> معلومات الطلب</h4>
                    <div class="details-grid">
                        <div><strong>التاريخ:</strong> ${order.created_at}</div>
                        <div><strong>الحالة:</strong> ${getStatusBadge(order.status)}</div>
                    </div>
                </div>
                
                ${order.items && order.items.length > 0 ? `
                <div class="details-section">
                    <h4><i class="fas fa-box"></i> المنتجات</h4>
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>السعر</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${order.items.map(item => `
                                <tr>
                                    <td>${escapeHtml(item.product_name || item.product_code)}</td>
                                    <td>${item.quantity}</td>
                                    <td>${formatMoney(item.unit_price)}</td>
                                    <td>${formatMoney(item.total_price)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ` : ''}
                
                <div class="details-section">
                    <h4><i class="fas fa-calculator"></i> المبالغ</h4>
                    <div class="details-amounts">
                        <div><span>المبلغ قبل الخصم:</span><strong>${formatMoney(order.subtotal_amount)}</strong></div>
                        <div><span>الخصم:</span><strong class="text-red-600">-${formatMoney(order.discount_amount)}</strong></div>
                        <div class="total"><span>المبلغ النهائي:</span><strong>${formatMoney(order.final_amount)}</strong></div>
                    </div>
                </div>
                
                ${order.notes ? `
                <div class="details-section">
                    <h4><i class="fas fa-sticky-note"></i> ملاحظات</h4>
                    <p>${escapeHtml(order.notes)}</p>
                </div>
                ` : ''}
            </div>
        `;
        
        panel.innerHTML = html;
        
    } catch (error) {
        console.error('Error loading order details:', error);
        panel.innerHTML = `<div class="error-message"><i class="fas fa-exclamation-triangle"></i> فشل تحميل التفاصيل</div>`;
    }
}

function closeOrderDetails() {
    document.getElementById('orderDetailsPanel').style.display = 'none';
}

// ============================================
// SHIPPING COST
// ============================================

function initializeShippingCost() {
    const shippingInput = document.getElementById('shipping_cost');
    if (shippingInput) {
        shippingInput.addEventListener('input', updateBasketDisplay);
    }
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function formatMoney(amount) {
    return parseFloat(amount || 0).toFixed(3) + ' ريال';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusBadge(status) {
    const badges = {
        'new': '<span class="badge-info">جديد</span>',
        'processing': '<span class="badge-warning">قيد المعالجة</span>',
        'completed': '<span class="badge-success">مكتمل</span>',
        'cancelled': '<span class="badge-danger">ملغي</span>',
        'in_basket': '<span class="badge-primary">في سلة</span>'
    };
    return badges[status] || status;
}
