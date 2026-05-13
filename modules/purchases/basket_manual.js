/**
 * Purchase Basket - Manual Entry JS
 * Version: 4.4
 * - Automatically fills the "Final Payment Price" input with the calculated "Grand Total".
 * - The "Final Payment Price" remains editable for manual overrides.
 * - Modified formatMoney function to display numbers in English (en-US locale).
 * - Handles all financial calculations based on direct user input.
 * - Manages dynamic visibility of payment source selectors.
 * - Adds live search/filter functionality for payment source dropdowns.
 * - NEW: No specific JS changes needed for multiple file upload itself, as browser handles input.
 */

console.log('🚀 Basket Manual JS Loaded (v4.4)');

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function () {
    console.log('✅ DOM Content Loaded');

    // --- FINANCIAL CALCULATION SETUP ---
    const financialInputs = [
        'subtotalInput', 'shippingCost', 'taxRate', 'manualDiscountInput',
        'points_discount', 'club_discount'
    ];
    financialInputs.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateTotals);
        }
    });
    const taxIncludedCheckbox = document.getElementById('taxIncluded');
    if (taxIncludedCheckbox) {
        taxIncludedCheckbox.addEventListener('change', updateTotals);
    }
    updateTotals(); // Initial calculation

    // --- PAYMENT SOURCE SELECTION LOGIC ---
    const paymentTypeSelect = document.getElementById('paymentSourceType');
    const paymentDetailsContainer = document.getElementById('paymentSourceDetails');
    const bankSelectorContainer = document.getElementById('bankAccountSelector');
    const cardSelectorContainer = document.getElementById('purchaseCardSelector');
    const bankSelect = document.getElementById('bankAccountSelect');
    const cardSelect = document.getElementById('purchaseCardSelect');
    const balanceDisplayContainer = document.getElementById('sourceBalanceContainer');
    const balanceDisplay = document.getElementById('sourceBalanceDisplay');
// --- IMAGE PREVIEW LOGIC ---
const attachmentInput = document.getElementById('attachment');
const previewContainer = document.getElementById('imagePreviewContainer');

if (attachmentInput && previewContainer) {
    attachmentInput.addEventListener('change', function() {
        // Clear existing previews
        previewContainer.innerHTML = '';

        if (this.files) {
            Array.from(this.files).forEach(file => {
                // Only process image files
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        // Create preview element
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        
                        div.appendChild(img);
                        previewContainer.appendChild(div);
                    }

                    reader.readAsDataURL(file);
                } else {
                    // Fallback for non-image files (like PDFs)
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.style.display = 'flex';
                    div.style.alignItems = 'center';
                    div.style.justifyContent = 'center';
                    div.innerHTML = '<i class="fas fa-file-alt fa-2x" style="color: #6b7280;"></i>';
                    previewContainer.appendChild(div);
                }
            });
        }
    });
}
    function handlePaymentTypeChange() {
        const selectedType = paymentTypeSelect.value;

        // Reset all related fields and hide containers
        paymentDetailsContainer.style.display = 'none';
        bankSelectorContainer.style.display = 'none';
        cardSelectorContainer.style.display = 'none';
        balanceDisplayContainer.style.display = 'none';
        balanceDisplay.textContent = '';
        bankSelect.value = '';
        cardSelect.value = '';

        // Disable the non-relevant select to prevent accidental submission
        bankSelect.disabled = true;
        cardSelect.disabled = true;

        if (selectedType === 'bank_account') {
            paymentDetailsContainer.style.display = 'block';
            bankSelectorContainer.style.display = 'block';
            bankSelect.disabled = false;
        } else if (selectedType === 'purchase_card') {
            paymentDetailsContainer.style.display = 'block';
            cardSelectorContainer.style.display = 'block';
            cardSelect.disabled = false;
        }
    }

    function updateSourceBalance(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const balance = selectedOption.getAttribute('data-balance');

        if (balance) {
            balanceDisplay.innerHTML = `<i class="fas fa-wallet"></i> Available Balance: ${formatMoney(balance)}`;
            balanceDisplayContainer.style.display = 'block';
        } else {
            balanceDisplayContainer.style.display = 'none';
            balanceDisplay.textContent = '';
        }
    }

    if (paymentTypeSelect) {
        paymentTypeSelect.addEventListener('change', handlePaymentTypeChange);
    }
    if (bankSelect) {
        bankSelect.addEventListener('change', () => updateSourceBalance(bankSelect));
    }
    if (cardSelect) {
        cardSelect.addEventListener('change', () => updateSourceBalance(cardSelect));
    }

    handlePaymentTypeChange(); // Run on page load

    // --- DROPDOWN SEARCH/FILTER LOGIC ---
    function setupSearchableDropdown(searchInputId, selectElementId) {
        const searchInput = document.getElementById(searchInputId);
        const selectElement = document.getElementById(selectElementId);

        if (!searchInput || !selectElement) return;

        searchInput.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase().trim();
            const options = selectElement.getElementsByTagName('option');

            for (let i = 0; i < options.length; i++) {
                const option = options[i];
                const optionText = option.textContent.toLowerCase();
                if (option.value === '') {
                    option.style.display = '';
                    continue;
                }
                if (optionText.includes(searchTerm)) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
        });
    }

    // Initialize the search for both dropdowns
    setupSearchableDropdown('bankAccountSearch', 'bankAccountSelect');
    setupSearchableDropdown('purchaseCardSearch', 'purchaseCardSelect');


    console.log('✅ Initialization Complete');
});


// ============================================
// TOTALS CALCULATION ENGINE
// ============================================

function updateTotals() {
    const subtotal = parseFloat(document.getElementById('subtotalInput').value) || 0;
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
    const taxIncluded = document.getElementById('taxIncluded').checked;

    const manualDiscount = parseFloat(document.getElementById('manualDiscountInput').value) || 0;
    const pointsDiscount = parseFloat(document.getElementById('points_discount').value) || 0;
    const clubDiscount = parseFloat(document.getElementById('club_discount').value) || 0;

    const totalDiscount = manualDiscount + pointsDiscount + clubDiscount;
    const baseForTax = subtotal - totalDiscount;
    let taxAmount = 0;
    let grandTotal = 0;

    if (taxIncluded) {
        if ((taxRate + 100) > 0) {
            taxAmount = (baseForTax * taxRate) / (100 + taxRate);
        }
        grandTotal = baseForTax + shippingCost;
    } else {
        taxAmount = baseForTax * (taxRate / 100);
        grandTotal = baseForTax + taxAmount + shippingCost;
    }

    // --- Update display elements ---
    document.getElementById('totalDiscountDisplay').textContent = formatMoney(totalDiscount);
    document.getElementById('taxAmountDisplay').textContent = formatMoney(taxAmount);
    document.getElementById('grandTotalDisplay').textContent = formatMoney(grandTotal);

    // --- NEW: Automatically fill the final price input with the grand total ---
    // The field remains editable for manual overrides.
    document.getElementById('final_price_override').value = grandTotal.toFixed(2);
}


// ============================================
// UTILITY FUNCTION
// ============================================

/**
 * MODIFIED: This function now formats numbers using English numerals and adds " YER" as the currency.
 * It also ensures two decimal places for a consistent financial look.
 */
function formatMoney(amount) {
    if (amount === null || isNaN(amount)) return '0.00 YER';

    // Options to ensure two decimal places are always shown
    const options = {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    };

    // 'en-US' locale uses standard Western Arabic numerals (0, 1, 2...)
    return new Intl.NumberFormat('en-US', options).format(parseFloat(amount)) + ' YER';
}