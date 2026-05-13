<?php
/**
 * Yemen Phone Number Utilities
 * Format: +967 XXX XXX XXX
 */

/**
 * Format phone number to Yemen format with RTL support
 * @param string $phone Raw phone number
 * @param bool $rtl Use RTL direction (default: true)
 * @return string Formatted phone number
 */
function formatYemenPhone($phone, $rtl = true) {
    if (empty($phone)) return '';
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Remove leading zeros
    $phone = ltrim($phone, '0');
    
    // Remove country code if already present
    if (substr($phone, 0, 3) === '967') {
        $phone = substr($phone, 3);
    }
    
    // Format: +967 XXX XXX XXX
    $formatted = '';
    if (strlen($phone) === 9) {
        $formatted = '+967 ' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3);
    } else if (strlen($phone) === 7) {
        // Landline format: +967 X XXX XXX
        $formatted = '+967 ' . substr($phone, 0, 1) . ' ' . substr($phone, 1, 3) . ' ' . substr($phone, 4, 3);
    } else {
        // Return with +967 prefix if valid length
        $formatted = '+967 ' . $phone;
    }
    
    // Wrap in RTL span if needed
    if ($rtl) {
        return '<span dir="ltr" style="unicode-bidi: plaintext;">' . $formatted . '</span>';
    }
    
    return $formatted;
}

/**
 * Validate Yemen phone number
 * @param string $phone Phone number to validate
 * @return bool True if valid
 */
function validateYemenPhone($phone) {
    if (empty($phone)) return false;
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Remove leading zeros
    $phone = ltrim($phone, '0');
    
    // Remove country code if present
    if (substr($phone, 0, 3) === '967') {
        $phone = substr($phone, 3);
    }
    
    // Yemen mobile numbers: 9 digits starting with 7
    // Yemen landline: 7 digits starting with 1-6
    return (strlen($phone) === 9 && $phone[0] === '7') || 
           (strlen($phone) === 7 && in_array($phone[0], ['1', '2', '3', '4', '5', '6']));
}

/**
 * Get clean phone number (digits only with country code)
 * @param string $phone Phone number
 * @return string Clean phone number (967XXXXXXXXX)
 */
function getCleanYemenPhone($phone) {
    if (empty($phone)) return '';
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Remove leading zeros
    $phone = ltrim($phone, '0');
    
    // Add country code if not present
    if (substr($phone, 0, 3) !== '967') {
        $phone = '967' . $phone;
    }
    
    return $phone;
}

/**
 * Format phone for WhatsApp (digits only with country code)
 * @param string $phone Phone number
 * @return string WhatsApp format (967XXXXXXXXX)
 */
function formatPhoneForWhatsApp($phone) {
    return getCleanYemenPhone($phone);
}

/**
 * Get phone number with clickable link (RTL-friendly)
 * @param string $phone Phone number
 * @param bool $whatsapp True for WhatsApp link
 * @return string HTML link
 */
function getPhoneLink($phone, $whatsapp = false) {
    if (empty($phone)) return '-';
    
    $formatted = formatYemenPhone($phone, true);
    $clean = getCleanYemenPhone($phone);
    
    if ($whatsapp) {
        return '<a href="https://wa.me/' . $clean . '" target="_blank" class="text-amber-600 hover:text-amber-800" dir="ltr" style="unicode-bidi: plaintext;">
                    <i class="fab fa-whatsapp"></i> ' . $formatted . '
                </a>';
    } else {
        return '<a href="tel:+' . $clean . '" class="text-blue-600 hover:text-blue-800" dir="ltr" style="unicode-bidi: plaintext;">
                    <i class="fas fa-phone"></i> ' . $formatted . '
                </a>';
    }
}

/**
 * Get Yemen mobile operators
 * @param string $phone Phone number
 * @return string Operator name
 */
function getYemenOperator($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $phone = ltrim($phone, '0');
    
    if (substr($phone, 0, 3) === '967') {
        $phone = substr($phone, 3);
    }
    
    if (strlen($phone) < 3) return 'Unknown';
    
    $prefix = substr($phone, 0, 3);
    
    // Yemen mobile operators
    $operators = [
        '770' => 'Yemen Mobile (Sabafon)',
        '771' => 'Yemen Mobile (Sabafon)',
        '772' => 'Yemen Mobile (Sabafon)',
        '773' => 'Yemen Mobile (Sabafon)',
        '774' => 'Yemen Mobile (Sabafon)',
        '775' => 'Yemen Mobile (Sabafon)',
        '776' => 'Yemen Mobile (Sabafon)',
        '777' => 'Yemen Mobile (Sabafon)',
        '778' => 'Yemen Mobile (Sabafon)',
        '779' => 'Yemen Mobile (Sabafon)',
        '780' => 'MTN Yemen',
        '781' => 'MTN Yemen',
        '782' => 'MTN Yemen',
        '783' => 'MTN Yemen',
        '784' => 'MTN Yemen',
        '785' => 'MTN Yemen',
        '786' => 'MTN Yemen',
        '787' => 'MTN Yemen',
        '788' => 'MTN Yemen',
        '789' => 'MTN Yemen',
        '790' => 'Yemen Mobile (Y)',
        '791' => 'Yemen Mobile (Y)',
        '792' => 'Yemen Mobile (Y)',
        '793' => 'Yemen Mobile (Y)',
        '794' => 'Yemen Mobile (Y)',
        '795' => 'Yemen Mobile (Y)',
        '796' => 'Yemen Mobile (Y)',
        '797' => 'Yemen Mobile (Y)',
        '798' => 'Yemen Mobile (Y)',
        '799' => 'Yemen Mobile (Y)',
        '700' => 'YOU (Aden Net)',
        '701' => 'YOU (Aden Net)',
        '702' => 'YOU (Aden Net)',
        '703' => 'YOU (Aden Net)',
        '704' => 'YOU (Aden Net)',
        '705' => 'YOU (Aden Net)',
        '706' => 'YOU (Aden Net)',
        '707' => 'YOU (Aden Net)',
        '708' => 'YOU (Aden Net)',
        '709' => 'YOU (Aden Net)',
        '710' => 'HiTS-Unitel',
        '711' => 'HiTS-Unitel',
        '712' => 'HiTS-Unitel',
        '713' => 'HiTS-Unitel',
        '714' => 'HiTS-Unitel',
        '715' => 'HiTS-Unitel',
        '716' => 'HiTS-Unitel',
        '717' => 'HiTS-Unitel',
        '718' => 'HiTS-Unitel',
        '719' => 'HiTS-Unitel',
        '730' => 'Yemen Mobile (Sabafon)',
        '731' => 'Yemen Mobile (Sabafon)',
        '732' => 'Yemen Mobile (Sabafon)',
        '733' => 'Yemen Mobile (Sabafon)',
        '734' => 'Yemen Mobile (Sabafon)',
        '735' => 'Yemen Mobile (Sabafon)',
        '736' => 'Yemen Mobile (Sabafon)',
        '737' => 'Yemen Mobile (Sabafon)',
        '738' => 'Yemen Mobile (Sabafon)',
        '739' => 'Yemen Mobile (Sabafon)',
    ];
    
    return $operators[$prefix] ?? 'Unknown';
}

/**
 * JavaScript validation function
 * @return string JavaScript code
 */
function getYemenPhoneValidationJS() {
    return <<<'JS'
function validateYemenPhone(phone) {
    if (!phone) return false;
    
    // Remove all non-numeric characters
    phone = phone.replace(/[^0-9]/g, '');
    
    // Remove leading zeros
    phone = phone.replace(/^0+/, '');
    
    // Remove country code if present
    if (phone.startsWith('967')) {
        phone = phone.substring(3);
    }
    
    // Yemen mobile: 9 digits starting with 7
    // Yemen landline: 7 digits starting with 1-6
    return (phone.length === 9 && phone[0] === '7') || 
           (phone.length === 7 && ['1','2','3','4','5','6'].includes(phone[0]));
}

function formatYemenPhone(phone) {
    if (!phone) return '';
    
    // Remove all non-numeric characters
    phone = phone.replace(/[^0-9]/g, '');
    
    // Remove leading zeros
    phone = phone.replace(/^0+/, '');
    
    // Remove country code if present
    if (phone.startsWith('967')) {
        phone = phone.substring(3);
    }
    
    // Format: +967 XXX XXX XXX
    if (phone.length === 9) {
        return '+967 ' + phone.substring(0, 3) + ' ' + phone.substring(3, 6) + ' ' + phone.substring(6, 9);
    } else if (phone.length === 7) {
        return '+967 ' + phone.substring(0, 1) + ' ' + phone.substring(1, 4) + ' ' + phone.substring(4, 7);
    }
    
    return '+967 ' + phone;
}
JS;
}

/**
 * HTML input field for Yemen phone
 * @param string $name Field name
 * @param string $value Current value
 * @param bool $required Required field
 * @param string $label Label text
 * @return string HTML input
 */
function yemenPhoneInput($name, $value = '', $required = false, $label = 'رقم الهاتف') {
    $formatted = formatYemenPhone($value);
    $req = $required ? 'required' : '';
    
    return <<<HTML
<div class="form-group">
    <label for="$name">
        <i class="fas fa-phone ml-1"></i>
        $label
        {($required ? '<span class="text-red-500">*</span>' : '')}
    </label>
    <input type="tel" 
           id="$name" 
           name="$name" 
           class="form-control" 
           value="$formatted"
           placeholder="+967 XXX XXX XXX"
           pattern="^(\+967|967|0)?[0-9]{7,9}$"
           $req>
    <small class="text-gray-500">مثال: +967 777 123 456</small>
</div>
HTML;
}
?>
