    </main> <!-- End of main content -->    

    <!-- Footer -->
    <footer class="bg-white shadow-inner text-gray-600 py-4 sm:mr-64 transition-all duration-300">
        <div class="px-6 mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <p class="text-sm">&copy; <?php echo date('Y'); ?> Yaman Accounting Calculator. جميع الحقوق محفوظة.</p>
                </div>
                <div class="flex space-x-4 space-x-reverse">
                    <a href="#" class="text-gray-500 hover:text-blue-600 transition duration-200">
                        <i class="fab fa-facebook"></i>
                    </a>
                    <a href="#" class="text-gray-500 hover:text-blue-600 transition duration-200">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="text-gray-500 hover:text-blue-600 transition duration-200">
                        <i class="fab fa-linkedin"></i>
                    </a>
                    <a href="#" class="text-gray-500 hover:text-blue-600 transition duration-200">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Loading spinner (hidden by default) -->
    <div id="loadingSpinner" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <div class="flex items-center">
                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>جاري التحميل...</span>
            </div>
        </div>
    </div>

    <!-- Global JavaScript functions -->
    <script>
        // Show loading spinner
        function showLoading() {
            document.getElementById('loadingSpinner').classList.remove('hidden');
        }

        // Hide loading spinner
        function hideLoading() {
            document.getElementById('loadingSpinner').classList.add('hidden');
        }

        // Show success message
        function showSuccess(message) {
            showNotification(message, 'success');
        }

        // Show error message
        function showError(message) {
            showNotification(message, 'error');
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-amber-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500';
            
            notification.className = `fixed top-4 left-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 transition-opacity duration-300`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="mr-2 text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Confirm delete dialog
        function confirmDelete(message = 'هل أنت متأكد من الحذف؟') {
            return confirm(message);
        }

        // Format number with Arabic (Yemen) locale
        function formatNumber(number) {
            return new Intl.NumberFormat('ar-YE').format(number);
        }

        // Format currency - always Yemeni Riyal
        function formatCurrency(amount) {
            return new Intl.NumberFormat('ar-YE', {
                style: 'currency',
                currency: 'YER'
            }).format(amount);
        }

        // Print function
        function printDiv(divId) {
            const printContent = document.getElementById(divId);
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = printContent.innerHTML;
            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }

        // Export to Excel function
        function exportToExcel(tableId, filename = 'export') {
            const table = document.getElementById(tableId);
            const workbook = XLSX.utils.table_to_book(table);
            XLSX.writeFile(workbook, filename + '.xlsx');
        }

        // Date picker initialization (if needed)
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize any date pickers or other components here
            
            // Add click handlers for dropdown menus
            document.querySelectorAll('[data-dropdown-toggle]').forEach(function(trigger) {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('data-dropdown-toggle');
                    const target = document.getElementById(targetId);
                    target.classList.toggle('hidden');
                });
            });
        });
    </script>
</body>
</html>
