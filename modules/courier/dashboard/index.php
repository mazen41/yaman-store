<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /courier-login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الموصل</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f3f4f6; margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header { background: linear-gradient(135deg, #059669 0%, #C7A46D 100%); color: white; padding: 1.5rem; }
        .card { background: white; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn { padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-success { background: #C7A46D; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-logout { background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.3); font-size: 0.875rem; transition: all 0.2s; }
        .btn-logout:hover { background: rgba(255,255,255,0.3); }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .tab { padding: 0.75rem 1.5rem; border-radius: 8px; background: white; color: #374151; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .tab.active { background: #C7A46D; color: white; }
        .success-msg { background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; display: none; }
        .error-msg { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; display: none; }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0;"><i class="fas fa-truck"></i> لوحة الموصل</h1>
                <p style="margin-top: 0.5rem; opacity: 0.9;">مرحباً، <?php echo htmlspecialchars($_SESSION['username'] ?? 'موصل'); ?></p>
            </div>
            <a href="../logout.php" class="btn-logout" style="text-decoration: none;">
                <i class="fas fa-sign-out-alt"></i> تسجيل خروج
            </a>
        </div>
    </div>

    <div style="padding: 1rem;">
        <div id="successMsg" class="success-msg"></div>
        <div id="errorMsg" class="error-msg"></div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem;">
            <div class="card" style="text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: #C7A46D;" id="readyCount">0</div>
                <div style="font-size: 0.875rem; color: #6b7280;">جاهزة</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 1.25rem; font-weight: 700; color: #f59e0b;" id="codTotal">0 ر.س</div>
                <div style="font-size: 0.875rem; color: #6b7280;">COD المحصّل</div>
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; overflow-x: auto;">
            <button class="tab active" onclick="filterOrders('ready')" id="tabReady">جاهزة</button>
            <button class="tab" onclick="filterOrders('completed')" id="tabCompleted">مكتملة</button>
            <button class="tab" onclick="filterOrders('failed')" id="tabFailed">فشلت</button>
        </div>

        <div id="ordersList"></div>
    </div>

    <div id="actionModal" class="modal">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%;">
            <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem;" id="modalTitle"></h3>
            <div id="modalBody" style="margin-bottom: 1.5rem;"></div>
            <div style="display: flex; gap: 1rem;">
                <button class="btn btn-success" onclick="confirmAction()" id="confirmBtn" style="flex: 1;">تأكيد</button>
                <button class="btn" style="background: #6b7280; color: white;" onclick="closeModal()">إلغاء</button>
            </div>
        </div>
    </div>

    <script>
    let orders = [];
    let currentFilter = 'ready';
    let selectedOrder = null;
    let selectedAction = null;

    function showMessage(msg, isError = false) {
        const successMsg = document.getElementById('successMsg');
        const errorMsg = document.getElementById('errorMsg');
        
        if (isError) {
            errorMsg.textContent = msg;
            errorMsg.style.display = 'block';
            successMsg.style.display = 'none';
            setTimeout(() => errorMsg.style.display = 'none', 5000);
        } else {
            successMsg.textContent = msg;
            successMsg.style.display = 'block';
            errorMsg.style.display = 'none';
            setTimeout(() => successMsg.style.display = 'none', 5000);
        }
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function loadOrders() {
        try {
            const response = await fetch('../api/orders.php?status=' + currentFilter);
            const data = await response.json();
            
            if (data.success) {
                orders = data.orders;
                updateSummary(data.summary);
                renderOrders();
            }
        } catch (error) {
            console.error(error);
        }
    }

    function updateSummary(summary) {
        document.getElementById('readyCount').textContent = summary.ready || 0;
        document.getElementById('codTotal').textContent = (summary.collected_cod || 0).toFixed(2) + ' ر.س';
    }

    function renderOrders() {
        const container = document.getElementById('ordersList');
        
        if (orders.length === 0) {
            container.innerHTML = '<div class="card" style="text-align: center; padding: 2rem; color: #6b7280;">لا توجد طلبات</div>';
            return;
        }

        container.innerHTML = orders.map(order => `
            <div class="card">
                <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                    <div>
                        <div style="font-weight: 700; font-size: 1.125rem;">${order.order_number}</div>
                        <div style="color: #6b7280; font-size: 0.875rem;">${order.customer_name || 'عميل'}</div>
                    </div>
                </div>
                
                ${order.customer_phone ? `<div style="margin-bottom: 0.5rem;"><i class="fas fa-phone" style="color: #C7A46D;"></i> <a href="tel:${order.customer_phone}" style="color: #C7A46D; text-decoration: none;">${order.customer_phone}</a></div>` : ''}
                ${order.customer_address ? `<div style="margin-bottom: 1rem;"><i class="fas fa-map-marker-alt" style="color: #ef4444;"></i> ${order.customer_address}</div>` : ''}
                ${order.cod_amount > 0 ? `<div style="background: #fef3c7; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;"><strong style="color: #92400e;">COD: ${parseFloat(order.cod_amount).toFixed(2)} ر.س</strong></div>` : ''}

                ${order.status === 'ready_for_delivery' ? `
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-success" style="flex: 1;" onclick="showAction(${order.id}, 'deliver', '${order.order_number}', ${order.cod_amount || 0})"><i class="fas fa-check"></i> تسليم ناجح</button>
                        <button class="btn btn-danger" onclick="showAction(${order.id}, 'fail', '${order.order_number}', 0)"><i class="fas fa-times"></i> فشل</button>
                    </div>
                ` : ''}
            </div>
        `).join('');
    }

    function showAction(orderId, action, orderNumber, codAmount) {
        selectedOrder = { id: orderId, order_number: orderNumber, cod_amount: codAmount };
        selectedAction = action;
        
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        if (action === 'deliver') {
            title.textContent = 'تأكيد التسليم';
            body.innerHTML = `
                <p style="margin-bottom: 1rem;">الطلب: <strong>${orderNumber}</strong></p>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">اسم المستلم</label>
                    <input type="text" id="receiverName" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;">
                </div>
                ${codAmount > 0 ? `
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">المبلغ المحصّل</label>
                        <input type="number" id="codCollected" value="${codAmount}" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;">
                    </div>
                ` : ''}
            `;
        } else {
            title.textContent = 'فشل التسليم';
            body.innerHTML = `
                <p style="margin-bottom: 1rem;">الطلب: <strong>${orderNumber}</strong></p>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">سبب الفشل</label>
                    <select id="failReason" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;">
                        <option value="العميل غير موجود">العميل غير موجود</option>
                        <option value="رفض الاستلام">رفض الاستلام</option>
                        <option value="عنوان خاطئ">عنوان خاطئ</option>
                        <option value="أخرى">أخرى</option>
                    </select>
                </div>
            `;
        }
        
        document.getElementById('actionModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('actionModal').classList.remove('active');
    }

    async function confirmAction() {
        const btn = document.getElementById('confirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...';
        
        try {
            let payload = {
                order_id: selectedOrder.id,
                action: selectedAction
            };
            
            if (selectedAction === 'deliver') {
                payload.receiver_name = document.getElementById('receiverName')?.value || '';
                payload.cod_collected = document.getElementById('codCollected')?.value || 0;
            } else {
                payload.fail_reason = document.getElementById('failReason')?.value || '';
            }
            
            const response = await fetch('../api/update_status.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            
            const data = await response.json();
            
            if (data.success) {
                closeModal();
                await loadOrders();
                showMessage('✅ ' + data.message);
            } else {
                showMessage('❌ ' + data.error, true);
            }
        } catch (error) {
            showMessage('❌ حدث خطأ في الاتصال', true);
        }
        
        btn.disabled = false;
        btn.innerHTML = 'تأكيد';
    }

    function filterOrders(filter) {
        currentFilter = filter;
        document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('active'));
        document.getElementById('tab' + filter.charAt(0).toUpperCase() + filter.slice(1)).classList.add('active');
        loadOrders();
    }

    loadOrders();
    setInterval(loadOrders, 30000);
    </script>
</body>
</html>
