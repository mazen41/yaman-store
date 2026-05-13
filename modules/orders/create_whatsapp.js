// WhatsApp notification functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle notification method checkboxes
    const whatsappCheckbox = document.getElementById('notification_whatsapp');
    const emailCheckbox = document.getElementById('notification_email');
    
    if (whatsappCheckbox) {
        whatsappCheckbox.addEventListener('change', function() {
            const templateContainer = document.getElementById('whatsapp_template_container');
            if (this.checked) {
                templateContainer.classList.remove('hidden');
            } else {
                templateContainer.classList.add('hidden');
            }
        });
    }
    
    if (emailCheckbox) {
        emailCheckbox.addEventListener('change', function() {
            const templateContainer = document.getElementById('email_template_container');
            if (this.checked) {
                templateContainer.classList.remove('hidden');
            } else {
                templateContainer.classList.add('hidden');
            }
        });
    }
    
    // Update form submission to handle WhatsApp manual editing
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        const originalSubmit = orderForm.onsubmit;
        
        orderForm.onsubmit = function(e) {
            // Check if original validation passes
            if (originalSubmit && typeof originalSubmit === 'function') {
                const result = originalSubmit.call(this, e);
                if (result === false) {
                    return false;
                }
            }
            
            // Check if WhatsApp notification is selected and manual editing is requested
            const whatsappChecked = document.getElementById('notification_whatsapp').checked;
            const manualEditing = document.getElementById('whatsapp_manual').checked;
            
            if (whatsappChecked && manualEditing) {
                // Store form data in session storage to retrieve after redirect
                const formData = new FormData(this);
                const formDataObj = {};
                
                formData.forEach((value, key) => {
                    // Handle array inputs like notification_method[]
                    if (key.includes('[]')) {
                        const baseKey = key.replace('[]', '');
                        if (!formDataObj[baseKey]) formDataObj[baseKey] = [];
                        formDataObj[baseKey].push(value);
                    } else {
                        formDataObj[key] = value;
                    }
                });
                
                // Store form data in session storage
                sessionStorage.setItem('pendingOrderData', JSON.stringify(formDataObj));
                
                // Get customer phone number
                const customerId = document.getElementById('customer_id').value;
                const customerOption = document.querySelector(`#customer_id option[value="${customerId}"]`);
                const whatsappNumber = customerOption ? customerOption.dataset.whatsapp || customerOption.dataset.mobile : '';
                
                // Get template ID
                const templateId = document.getElementById('whatsapp_template').value;
                
                // Redirect to WhatsApp send page
                e.preventDefault();
                window.location.href = `../notifications/send_whatsapp.php?customer_id=${customerId}&template_id=${templateId}&redirect=${encodeURIComponent(window.location.href)}`;
                return false;
            }
            
            return true;
        };
    }
    
    // Update customer selection to handle notification options
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect) {
        const originalChangeHandler = customerSelect.onchange;
        
        customerSelect.onchange = function() {
            // Call original handler if exists
            if (originalChangeHandler && typeof originalChangeHandler === 'function') {
                originalChangeHandler.call(this);
            }
            
            const selectedOption = this.options[this.selectedIndex];
            if (this.value) {
                // Enable/disable notification options based on available contact info
                const whatsappOption = document.getElementById('notification_whatsapp');
                const emailOption = document.getElementById('notification_email');
                
                if (whatsappOption) {
                    const hasWhatsapp = selectedOption.dataset.whatsapp || selectedOption.dataset.mobile;
                    whatsappOption.disabled = !hasWhatsapp;
                    const whatsappContainer = document.querySelector('[data-method="whatsapp"]');
                    if (whatsappContainer) {
                        if (hasWhatsapp) {
                            whatsappContainer.classList.remove('opacity-50');
                        } else {
                            whatsappContainer.classList.add('opacity-50');
                            whatsappOption.checked = false;
                            document.getElementById('whatsapp_template_container').classList.add('hidden');
                        }
                    }
                }
                
                if (emailOption) {
                    const hasEmail = selectedOption.dataset.email;
                    emailOption.disabled = !hasEmail;
                    const emailContainer = document.querySelector('[data-method="email"]');
                    if (emailContainer) {
                        if (hasEmail) {
                            emailContainer.classList.remove('opacity-50');
                        } else {
                            emailContainer.classList.add('opacity-50');
                            emailOption.checked = false;
                            document.getElementById('email_template_container').classList.add('hidden');
                        }
                    }
                }
            }
        };
    }
});
