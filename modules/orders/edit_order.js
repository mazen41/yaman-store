let newProductIndex = document.querySelectorAll('.product-row').length;

function showAddProductModal() {
    document.getElementById('addProductModal').style.display = 'flex';
}

function closeAddProductModal() {
    document.getElementById('addProductModal').style.display = 'none';
    document.getElementById('newProductId').value = '';
    document.getElementById('newProductQuantity').value = '1';
    document.getElementById('newProductPrice').value = '0';
}

document.getElementById('newProductId').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const price = selected.getAttribute('data-price');
    document.getElementById('newProductPrice').value = price || 0;
});

function addNewProduct() {
    const productId = document.getElementById('newProductId').value;
    const productName = document.getElementById('newProductId').options[document.getElementById('newProductId').selectedIndex].text;
    const quantity = document.getElementById('newProductQuantity').value;
    const price = document.getElementById('newProductPrice').value;

    if (!productId || !quantity || !price) {
        alert('الرجاء ملء جميع الحقول');
        return;
    }

    const container = document.getElementById('productsContainer');
    const newRow = document.createElement('div');
    newRow.className = 'product-row';
    newRow.innerHTML = `
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto; gap: 1rem; align-items: center;">
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; color: #6b7280;">المنتج</label>
                <input type="text" class="input-field" value="${productName.split(' - ')[0]}" readonly>
                <input type="hidden" name="new_items[${newProductIndex}][product_id]" value="${productId}">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; color: #6b7280;">الكمية</label>
                <input type="number" name="new_items[${newProductIndex}][quantity]" class="input-field item-quantity" value="${quantity}" min="1" onchange="calculateTotals()">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; color: #6b7280;">السعر</label>
                <input type="number" name="new_items[${newProductIndex}][price]" class="input-field item-price" value="${price}" step="0.01" min="0" onchange="calculateTotals()">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; color: #6b7280;">الخصم</label>
                <input type="number" name="new_items[${newProductIndex}][discount]" class="input-field item-discount" value="0" step="0.01" min="0" onchange="calculateTotals()">
            </div>
            <div>
                <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; color: #6b7280;">الإجمالي</label>
                <input type="text" class="input-field item-total" readonly value="${(quantity * price).toFixed(2)}">
            </div>
            <div style="padding-top: 1.5rem;">
                <button type="button" onclick="removeProduct(this)" class="btn btn-danger" style="padding: 0.5rem 1rem;">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(newRow);
    newProductIndex++;
    
    closeAddProductModal();
    calculateTotals();
}

function removeProduct(btn) {
    if (confirm('هل أنت متأكد من حذف هذا المنتج؟')) {
        btn.closest('.product-row').remove();
        calculateTotals();
    }
}

function calculateTotals() {
    let subtotal = 0;

    document.querySelectorAll('.product-row').forEach(row => {
        const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const discount = parseFloat(row.querySelector('.item-discount').value) || 0;
        const total = (quantity * price) - discount;
        
        row.querySelector('.item-total').value = total.toFixed(2);
        subtotal += total;
    });

    document.getElementById('subtotal').value = subtotal.toFixed(2);

    const damagedDiscount = parseFloat(document.getElementById('damagedDiscount').value) || 0;
    const additionalDiscount = parseFloat(document.getElementById('additionalDiscount').value) || 0;
    const tax = parseFloat(document.getElementById('taxAmount').value) || 0;
    const shipping = parseFloat(document.getElementById('shippingCost').value) || 0;

    const finalTotal = subtotal - damagedDiscount - additionalDiscount + tax + shipping;
    document.getElementById('finalTotal').value = finalTotal.toFixed(2);
}

document.getElementById('editOrderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';

    try {
        const formData = new FormData(this);
        
        const response = await fetch('update_order.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ تم حفظ التعديلات بنجاح');
            window.location.href = 'view.php?id=' + formData.get('order_id');
        } else {
            alert('❌ ' + data.error);
        }
    } catch (error) {
        alert('❌ حدث خطأ: ' + error.message);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> حفظ التعديلات';
});

calculateTotals();
