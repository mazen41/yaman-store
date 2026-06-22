/**
 * Purchase Basket - Manual Entry JS
 * Version: 4.7
 * - Fixed: all inputs start empty (no default 0)
 * - Fixed: shipping SAR -> YER auto-conversion
 * - Fixed: points/club discount YER conversion
 * - Fixed: grand total SAR display
 * - Fixed: tax amount SAR display
 * - Fixed: total discount SAR display
 */

console.log('🚀 Basket Manual JS Loaded (v4.7)');

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function () {
    console.log('✅ DOM Content Loaded');

    // --- CLEAR DEFAULT VALUES: make all numeric inputs start empty ---
    // Skip this logic on edit page. We detect edit mode from the form's
    // data-mode attribute (set by the PHP page) rather than the URL query
    // string, because URL rewriting / clean URLs / cached JS could make a
    // window.location.search check unreliable and wipe real saved values.
    const basketFormEl = document.getElementById('basketForm');
    const isEditPage = basketFormEl && basketFormEl.dataset.mode === 'edit';

    if (!isEditPage) {
        const inputsToClear = [
            'sarInput', 'subtotalInput', 'shippingCost', 'shippingCostSar',
            'taxRate', 'manualDiscountInput', 'points_discount', 'club_discount',
            'totalProductsInput', 'grandTotalDisplay',
            'manualDiscountCurrencyDisplay', 'pointsDiscountCurrencyDisplay',
            'clubDiscountCurrencyDisplay', 'finalPriceOverrideSar', 'final_price_override'
        ];
        inputsToClear.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    // --- FINANCIAL CALCULATION SETUP ---
    const financialInputs = [
        'sarInput', 'subtotalInput', 'shippingCost', 'shippingCostSar', 'taxRate',
        'manualDiscountInput', 'points_discount', 'club_discount', 'yerExchangeRateInput',
        'manualDiscountCurrencyDisplay', 'pointsDiscountCurrencyDisplay',
        'clubDiscountCurrencyDisplay', 'grandTotalDisplay'
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

    // --- BIDIRECTIONAL CONVERSION SETUP ---
    window._biPairs = [
        { sar: 'manualDiscountInput',  yer: 'manualDiscountCurrencyDisplay',  sarBadge: 'manualDiscount_sarBadge',  yerBadge: 'manualDiscount_yerBadge'  },
        { sar: 'points_discount',      yer: 'pointsDiscountCurrencyDisplay',  sarBadge: 'pointsDiscount_sarBadge',  yerBadge: 'pointsDiscount_yerBadge'  },
        { sar: 'club_discount',        yer: 'clubDiscountCurrencyDisplay',    sarBadge: 'clubDiscount_sarBadge',    yerBadge: 'clubDiscount_yerBadge'    },
        { sar: 'sarInput',             yer: 'subtotalInput',                  sarBadge: 'subtotal_sarBadge',        yerBadge: 'subtotal_yerBadge'        },
        { sar: 'shippingCostSar',      yer: 'shippingCost',                   sarBadge: 'shipping_sarBadge',        yerBadge: 'shipping_yerBadge'        },
    ];

    // Inject SAR/YER badge spans next to each field if not already present
    window._biPairs.forEach(pair => {
        injectBadge(pair.sar, pair.sarBadge, 'sar');
        injectBadge(pair.yer, pair.yerBadge, 'yer');
    });

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

function getRate() {
    const r = parseFloat(document.getElementById('yerExchangeRateInput')?.value) || 140;
    return r > 0 ? r : 140;
}

function updateTotals() {
    const rate = getRate();
    const sarInput     = document.getElementById('sarInput');
    const subtotalInput = document.getElementById('subtotalInput');
    const shippingCostSarEl = document.getElementById('shippingCostSar');
    const shippingCostYerEl = document.getElementById('shippingCost');

    // SAR subtotal -> YER subtotal
    if (sarInput && document.activeElement === sarInput) {
        const sarVal = parseFloat(sarInput.value) || 0;
        if (subtotalInput) subtotalInput.value = (sarVal * rate).toFixed(2);
    }
    // YER subtotal -> SAR subtotal
    if (subtotalInput && document.activeElement === subtotalInput) {
        const yerVal = parseFloat(subtotalInput.value) || 0;
        if (sarInput) sarInput.value = (yerVal / rate).toFixed(4);
    }

    // SAR shipping -> YER shipping
    if (shippingCostSarEl && document.activeElement === shippingCostSarEl) {
        const sarShip = parseFloat(shippingCostSarEl.value) || 0;
        if (shippingCostYerEl) shippingCostYerEl.value = (sarShip * rate).toFixed(2);
    }
    // YER shipping -> SAR shipping
    if (shippingCostYerEl && document.activeElement === shippingCostYerEl) {
        const yerShip = parseFloat(shippingCostYerEl.value) || 0;
        if (shippingCostSarEl) shippingCostSarEl.value = (yerShip / rate).toFixed(4);
    }

    // SAR discounts -> YER discounts
    const discountPairs = [
        { sar: 'manualDiscountInput', yer: 'manualDiscountCurrencyDisplay' },
        { sar: 'points_discount',     yer: 'pointsDiscountCurrencyDisplay' },
        { sar: 'club_discount',       yer: 'clubDiscountCurrencyDisplay'   },
    ];
    discountPairs.forEach(({ sar, yer }) => {
        const sarEl = document.getElementById(sar);
        const yerEl = document.getElementById(yer);
        if (!sarEl || !yerEl) return;
        if (document.activeElement === sarEl) {
            yerEl.value = ((parseFloat(sarEl.value) || 0) * rate).toFixed(2);
        } else if (document.activeElement === yerEl) {
            sarEl.value = ((parseFloat(yerEl.value) || 0) / rate).toFixed(4);
        }
    });

    // --- Read current YER values for calculation ---
    const subtotal       = parseFloat(subtotalInput?.value) || 0;
    const shippingCostYer = parseFloat(shippingCostYerEl?.value) || 0;
    const taxRate        = parseFloat(document.getElementById('taxRate')?.value) || 0;
    const taxIncluded    = document.getElementById('taxIncluded')?.checked;

    const manualDiscountYer = parseFloat(document.getElementById('manualDiscountCurrencyDisplay')?.value) || 0;
    const pointsDiscountYer = parseFloat(document.getElementById('pointsDiscountCurrencyDisplay')?.value) || 0;
    const clubDiscountYer   = parseFloat(document.getElementById('clubDiscountCurrencyDisplay')?.value) || 0;
    const totalDiscountYer  = manualDiscountYer + pointsDiscountYer + clubDiscountYer;

    // SAR equivalents for display
    const manualDiscountSar = parseFloat(document.getElementById('manualDiscountInput')?.value) || 0;
    const pointsDiscountSar = parseFloat(document.getElementById('points_discount')?.value) || 0;
    const clubDiscountSar   = parseFloat(document.getElementById('club_discount')?.value) || 0;
    const totalDiscountSar  = manualDiscountSar + pointsDiscountSar + clubDiscountSar;
    const sarVal            = parseFloat(sarInput?.value) || 0;
    const shippingCostSarVal = parseFloat(shippingCostSarEl?.value) || 0;

    const baseForTaxYer = subtotal - totalDiscountYer;
    const baseForTaxSar = sarVal - totalDiscountSar;
    let taxAmountYer = 0, taxAmountSar = 0;
    let grandTotalYer = 0, grandTotalSar = 0;

    if (taxIncluded) {
        taxAmountYer  = taxRate > 0 ? (baseForTaxYer * taxRate) / (100 + taxRate) : 0;
        taxAmountSar  = taxRate > 0 ? (baseForTaxSar * taxRate) / (100 + taxRate) : 0;
        grandTotalYer = baseForTaxYer + shippingCostYer;
        grandTotalSar = baseForTaxSar + shippingCostSarVal;
    } else {
        taxAmountYer  = baseForTaxYer * (taxRate / 100);
        taxAmountSar  = baseForTaxSar * (taxRate / 100);
        grandTotalYer = baseForTaxYer + taxAmountYer + shippingCostYer;
        grandTotalSar = baseForTaxSar + taxAmountSar + shippingCostSarVal;
    }

    // --- Update all display elements ---
    const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };

    set('taxAmountDisplay',        formatMoney(taxAmountYer));
    set('taxAmountSarDisplay',     formatSarMoney(taxAmountSar));
    set('totalDiscountDisplay',    formatMoney(totalDiscountYer));
    set('totalDiscountSarDisplay', formatSarMoney(totalDiscountSar));
    set('grandTotalSarDisplay',    grandTotalSar > 0 ? grandTotalSar.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) : '0.00');

    // Update hidden fields
    const setVal = (id, val) => { const el = document.getElementById(id); if (el && document.activeElement !== el) el.value = val.toFixed(2); };
    setVal('taxCurrencyDisplay',          taxAmountYer);
    setVal('totalDiscountCurrencyDisplay', totalDiscountYer);
    setVal('grandTotalDisplay',            grandTotalYer);

    // Auto-fill final price override
    const finalOverrideYer = document.getElementById('final_price_override');
    if (finalOverrideYer && document.activeElement !== finalOverrideYer) finalOverrideYer.value = grandTotalYer.toFixed(2);
    const finalOverrideSar = document.getElementById('finalPriceOverrideSar');
    if (finalOverrideSar && document.activeElement !== finalOverrideSar) finalOverrideSar.value = grandTotalSar.toFixed(2);

    // Update all conversion badges
    updateAllBadges(rate);
}

// ============================================
// BIDIRECTIONAL BADGE SYSTEM
// ============================================

/**
 * Injects a conversion badge span immediately after an input element.
 * type: 'sar' means this input holds YER and badge shows SAR equivalent
 *       'yer' means this input holds SAR and badge shows YER equivalent
 */
function injectBadge(inputId, badgeId, type) {
    const input = document.getElementById(inputId);
    if (!input || document.getElementById(badgeId)) return;

    const badge = document.createElement('span');
    badge.id = badgeId;
    badge.className = 'currency-convert-badge';
    badge.setAttribute('data-type', type);
    badge.setAttribute('data-for', inputId);
    badge.style.cssText = [
        'display:inline-block',
        'font-size:11px',
        'font-weight:700',
        'padding:2px 7px',
        'border-radius:10px',
        'margin-top:4px',
        'white-space:nowrap',
        'transition:opacity .2s',
        type === 'sar'
            ? 'background:#e6f4ea;color:#1d6f42;border:1px solid #b7dfbf'    // green = SAR
            : 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d',   // amber = YER
    ].join(';');

    // Insert badge below the input by wrapping it or appending after
    const parent = input.parentNode;
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;flex-direction:column;align-items:flex-end;gap:2px;';
    parent.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    wrapper.appendChild(badge);
}

function updateAllBadges(rate) {
    if (!window._biPairs) return;
    window._biPairs.forEach(pair => {
        const sarVal = parseFloat(document.getElementById(pair.sar)?.value) || 0;
        const yerVal = parseFloat(document.getElementById(pair.yer)?.value) || 0;

        // Badge next to SAR field: show YER equivalent
        const sarBadge = document.getElementById(pair.sarBadge);
        if (sarBadge) {
            const yerEquiv = sarVal * rate;
            sarBadge.textContent = sarVal > 0 ? `= ${yerEquiv.toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:0})} YER` : '';
            sarBadge.style.opacity = sarVal > 0 ? '1' : '0';
        }

        // Badge next to YER field: show SAR equivalent
        const yerBadge = document.getElementById(pair.yerBadge);
        if (yerBadge) {
            const sarEquiv = rate > 0 ? yerVal / rate : 0;
            yerBadge.textContent = yerVal > 0 ? `= ${sarEquiv.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})} SAR` : '';
            yerBadge.style.opacity = yerVal > 0 ? '1' : '0';
        }
    });

    // Special: main SAR input -> show YER on its own badge
    const sarMainInput = document.getElementById('sarInput');
    const sarMainBadge = document.getElementById('sarMain_yerBadge');
    if (sarMainInput && sarMainBadge) {
        const v = parseFloat(sarMainInput.value) || 0;
        sarMainBadge.textContent = v > 0 ? `= ${(v * rate).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:0})} YER` : '';
        sarMainBadge.style.opacity = v > 0 ? '1' : '0';
    }
}

// Wire up shippingCostSar input (not covered by the main financialInputs array yet)
document.addEventListener('DOMContentLoaded', function() {
    const shippingCostSarEl = document.getElementById('shippingCostSar');
    if (shippingCostSarEl) shippingCostSarEl.addEventListener('input', updateTotals);
}, { once: true });


// ============================================
// UTILITY FUNCTIONS
// ============================================
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

function setCurrencyInput(elementId, yerAmount, exchangeRate) {
    const element = document.getElementById(elementId);
    if (!element) return;
    if (document.activeElement === element) return;
    const normalizedRate = exchangeRate > 0 ? exchangeRate : 140;
    const value = yerAmount;
    element.value = Number.isFinite(value) ? value.toFixed(2) : '0.00';
}

function formatSarMoney(amount) {
    if (amount === null || isNaN(amount)) return '0.00 SAR';
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(parseFloat(amount)) + ' SAR';
}
