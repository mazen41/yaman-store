// Mobile Sidebar Toggle Script for Employee Permissions
// Wrap in IIFE to avoid conflicts with jQuery
(function() {
'use strict';

document.addEventListener('DOMContentLoaded', function() {
    const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
    const employeesSidebar = document.getElementById('employeesSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const closeSidebar = document.getElementById('closeSidebar');
    
    // Open sidebar
    if (mobileSidebarToggle) {
        mobileSidebarToggle.addEventListener('click', function() {
            employeesSidebar.classList.remove('translate-x-full');
            employeesSidebar.classList.add('translate-x-0');
            sidebarOverlay.classList.remove('hidden');
        });
    }
    
    // Close sidebar
    function closeSidebarFunc() {
        employeesSidebar.classList.add('translate-x-full');
        employeesSidebar.classList.remove('translate-x-0');
        sidebarOverlay.classList.add('hidden');
    }
    
    if (closeSidebar) {
        closeSidebar.addEventListener('click', closeSidebarFunc);
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebarFunc);
    }
    
    // Close sidebar when clicking on a user
    const userLinks = employeesSidebar.querySelectorAll('a[href*="user_id"]');
    userLinks.forEach(link => {
        link.addEventListener('click', function() {
            // Small delay to allow navigation
            setTimeout(closeSidebarFunc, 100);
        });
    });
    
    // ========================================
    // CHECKBOX FUNCTIONALITY FIX - BULLETPROOF VERSION
    // ========================================
    
    console.log('🔧 Initializing checkbox handlers...');
    
    // Method 1: Handle checkbox label clicks properly
    const checkboxLabels = document.querySelectorAll('label.cursor-pointer');
    console.log('Found labels:', checkboxLabels.length);
    
    checkboxLabels.forEach((label, index) => {
        // Remove any existing click handlers
        const newLabel = label.cloneNode(true);
        label.parentNode.replaceChild(newLabel, label);
        
        newLabel.addEventListener('click', function(e) {
            console.log('Label clicked:', index);
            
            // Find the checkbox inside this label
            const checkbox = this.querySelector('input[type="checkbox"]');
            
            if (checkbox) {
                // If clicking directly on checkbox, let it handle naturally
                if (e.target === checkbox) {
                    console.log('Direct checkbox click');
                    return;
                }
                
                // Prevent default to avoid double-toggle
                e.preventDefault();
                e.stopPropagation();
                
                // Toggle the checkbox
                checkbox.checked = !checkbox.checked;
                console.log('Checkbox toggled to:', checkbox.checked);
                
                // Trigger change event
                const changeEvent = new Event('change', { bubbles: true });
                checkbox.dispatchEvent(changeEvent);
                
                // Update visual state
                updateCheckboxVisual(this, checkbox);
            }
        }, true); // Use capture phase
    });
    
    // Method 2: Direct checkbox change handlers (backup method)
    const allCheckboxes = document.querySelectorAll('input[type="checkbox"][name="permissions[]"]');
    console.log('Found checkboxes:', allCheckboxes.length);
    
    allCheckboxes.forEach((checkbox, index) => {
        // Change event
        checkbox.addEventListener('change', function(e) {
            console.log('Checkbox change event:', index, 'checked:', this.checked);
            const label = this.closest('label');
            if (label) {
                updateCheckboxVisual(label, this);
            }
        });
        
        // Click event (backup)
        checkbox.addEventListener('click', function(e) {
            console.log('Checkbox click event:', index);
            // Let it bubble naturally
        });
    });
    
    function updateCheckboxVisual(label, checkbox) {
        const isChecked = checkbox.checked;
        
        // Update border and shadow
        if (isChecked) {
            // Add checked styling
            if (label.classList.contains('bg-blue-50')) {
                label.classList.add('border-blue-500', 'shadow-md');
                label.classList.remove('border-transparent');
            } else if (label.classList.contains('bg-green-50')) {
                label.classList.add('border-green-500', 'shadow-md');
                label.classList.remove('border-transparent');
            } else if (label.classList.contains('bg-amber-50')) {
                label.classList.add('border-amber-500', 'shadow-md');
                label.classList.remove('border-transparent');
            }
            
            // Add check icon if not exists
            if (!label.querySelector('.fa-check-circle')) {
                const checkIcon = document.createElement('i');
                checkIcon.className = 'fas fa-check-circle text-green-500';
                const iconContainer = label.querySelector('.flex.items-center.justify-between');
                if (iconContainer) {
                    iconContainer.appendChild(checkIcon);
                }
            }
        } else {
            // Remove checked styling
            label.classList.remove('border-blue-500', 'border-green-500', 'border-amber-500', 'shadow-md');
            label.classList.add('border-transparent');
            
            // Remove check icon
            const checkIcon = label.querySelector('.fa-check-circle');
            if (checkIcon) {
                checkIcon.remove();
            }
        }
    }
    
    // Final initialization summary
    const finalCheckboxCount = document.querySelectorAll('input[type="checkbox"][name="permissions[]"]').length;
    const finalLabelCount = document.querySelectorAll('label.cursor-pointer').length;
    
    console.log('✅ CHECKBOX INITIALIZATION COMPLETE');
    console.log('📊 Summary:');
    console.log('  - Checkboxes found:', finalCheckboxCount);
    console.log('  - Labels found:', finalLabelCount);
    console.log('  - Handlers attached: click, change');
    console.log('  - Visual updates: enabled');
    console.log('🎯 Checkboxes are now fully functional!');
});

})(); // End IIFE
