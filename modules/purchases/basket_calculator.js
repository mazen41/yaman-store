// Auto-calculate final amount
function calculateFinalAmount() {
    const subtotal = parseFloat(document.getElementById('subtotal_amount')?.value || 0);
    const discount = parseFloat(document.getElementById('discount_amount')?.value || 0);
    const tax = parseFloat(document.getElementById('tax_amount')?.value || 0);
    const shipping = parseFloat(document.getElementById('shipping_cost')?.value || 0);
    
    const finalAmount = subtotal - discount + tax + shipping;
    
    const finalInput = document.getElementById('final_amount');
    if (finalInput && !finalInput.dataset.manualEdit) {
        finalInput.value = finalAmount.toFixed(2);
    }
}

// Mark as manually edited
document.getElementById('final_amount')?.addEventListener('input', function() {
    this.dataset.manualEdit = 'true';
});

// Add listeners to all amount fields
['subtotal_amount', 'discount_amount', 'tax_amount', 'shipping_cost'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', calculateFinalAmount);
});

// Calculate on page load
document.addEventListener('DOMContentLoaded', calculateFinalAmount);
