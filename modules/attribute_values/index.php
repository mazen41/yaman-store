<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// Initialize permissions
$can_view = false;
$can_add = false;
$can_edit = false;

if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_view = hasPermission($_SESSION['user_id'], 'attribute_values', 'view');
    $can_add = hasPermission($_SESSION['user_id'], 'attribute_values', 'add');
    $can_edit = hasPermission($_SESSION['user_id'], 'attribute_values', 'edit');
}

$page_title = 'إدارة قيم السمة';
$error_message = '';
$success_message = '';

$attribute_id = $_GET['attribute_id'] ?? null;
if (!$attribute_id) {
    // If no attribute_id is provided, redirect to attributes index or show an error
    header('Location: ../attributes/index.php');
    exit();
}

$attribute_name = '';
try {
    $stmt = $db->prepare("SELECT name FROM attributes WHERE id = ?");
    $stmt->execute([$attribute_id]);
    $attr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($attr) {
        $attribute_name = $attr['name'];
    } else {
        $error_message = 'السمة الأصلية غير موجودة.';
        // Optionally redirect
        // header('Location: ../attributes/index.php'); exit();
    }
} catch (PDOException $e) {
    $error_message = "خطأ في جلب اسم السمة: " . $e->getMessage();
}


// --- Handle Actions (Toggle Status / Delete) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    if (!$can_edit) {
        $error_message = 'ليس لديك صلاحية لتنفيذ هذا الإجراء';
    } else {
        $value_id = $_GET['id'];
        try {
            if ($_GET['action'] == 'toggle_active') {
                $current_status = $_GET['status'] ?? 0;
                $new_status = $current_status == 1 ? 0 : 1;
                $stmt = $db->prepare("UPDATE attribute_values SET is_active = ?, updated_at = NOW() WHERE id = ? AND attribute_id = ?");
                $stmt->execute([$new_status, $value_id, $attribute_id]);
                $success_message = $new_status == 1 ? 'تم تفعيل قيمة السمة بنجاح.' : 'تم تعطيل قيمة السمة بنجاح.';
            } elseif ($_GET['action'] == 'delete') {
                // Soft delete: set is_active to 0
                $stmt = $db->prepare("UPDATE attribute_values SET is_active = 0, updated_at = NOW() WHERE id = ? AND attribute_id = ?");
                $stmt->execute([$value_id, $attribute_id]);
                $success_message = 'تم حذف قيمة السمة بنجاح (أصبحت غير نشطة).';
            }
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تنفيذ الإجراء: ' . $e->getMessage();
        }
    }
}

// --- Get Filter and Sort Parameters ---
$search = $_GET['search'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active'; // active, inactive, all
$sort_by = $_GET['sort_by'] ?? 'display_order';
$sort_dir = $_GET['sort_dir'] ?? 'ASC';

$sort_options = [
    'display_order' => 'display_order',
    'value_alpha' => 'value',
    'created_at' => 'created_at',
    'updated_at' => 'updated_at',
];
$sort_column = $sort_options[$sort_by] ?? 'display_order';
$sort_direction = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

// Determine if any ADVANCED filter is active
$advanced_filters_active = ($sort_by != 'display_order') ||
                           ($sort_dir != 'ASC') ||
                           ($filter_status != 'active');

// --- Pagination ---
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// --- Build The Main SQL Query ---
$where_clauses = ["attribute_id = ?"];
$params = [$attribute_id];

// Apply status filter
if ($filter_status == 'active') {
    $where_clauses[] = "is_active = 1";
} elseif ($filter_status == 'inactive') {
    $where_clauses[] = "is_active = 0";
}

// Apply search filter
if ($search) {
    $where_clauses[] = "value LIKE ?";
    $params[] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

$attribute_values = [];
$total_attribute_values = 0;
$total_pages = 0;

if ($can_view) {
    try {
        // Main query for fetching attribute values data
        $sql = "SELECT * FROM attribute_values WHERE {$where_sql} ORDER BY {$sort_column} {$sort_direction} LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attribute_values = $stmt->fetchAll();

        // Count query for pagination
        $count_sql = "SELECT COUNT(*) FROM attribute_values WHERE {$where_sql}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($params);
        $total_attribute_values = $count_stmt->fetchColumn();
        $total_pages = ceil($total_attribute_values / $limit);

        // Adjust page if it exceeds total pages after filtering
        if ($page > $total_pages && $total_pages > 0) {
            $page = $total_pages;
            $offset = ($page - 1) * $limit;
            $stmt = $db->prepare($sql); // Re-run query with corrected offset
            $stmt->execute($params);
            $attribute_values = $stmt->fetchAll();
        } else if ($total_pages === 0) {
            $page = 1;
            $offset = 0;
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب البيانات: " . $e->getMessage();
        $attribute_values = [];
        $total_attribute_values = 0;
        $total_pages = 0;
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">قيم السمة: <?php echo htmlspecialchars($attribute_name); ?></h1>
                    <p class="text-gray-600 mt-1">إدارة المقاسات أو المواصفات الخاصة بالسمة.</p>
                </div>
                <div class="mt-4 sm:mt-0 flex items-center space-x-3 space-x-reverse">
                    <!-- Toggle Filters Button -->
                    <button type="button" id="toggleAdvancedFiltersBtn"
                        class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200 text-sm">
                        <i class="fas fa-filter ml-2"></i> <span id="toggleText"><?php echo $advanced_filters_active ? 'إخفاء الفلاتر المتقدمة' : 'إظهار الفلاتر المتقدمة'; ?></span>
                    </button>
                    
                    <?php if ($can_add): ?>
                        <a href="add.php?attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i> قيمة جديدة
                        </a>
                    <?php endif; ?>
                    <a href="../attributes/view.php?id=<?php echo htmlspecialchars($attribute_id); ?>"
                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للسمة
                    </a>
                </div>
            </div>

            <!-- Filters and Sorting Form -->
            <form method="GET">
                <input type="hidden" name="attribute_id" value="<?php echo htmlspecialchars($attribute_id); ?>">
                <!-- Always Visible Search and Action Row -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex flex-wrap gap-4 items-end">
                        <div style="flex-grow: 1;">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">بحث (القيمة)</label>
                            <input type="text" name="search" id="search" placeholder="ابحث بالقيمة..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div class="flex items-end gap-2" style="padding-bottom: 2px;">
                            <button type="submit"
                                class="w-full px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"><i
                                    class="fas fa-search ml-1"></i>بحث</button>
                            <a href="index.php?attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                                class="w-full text-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400"><i
                                    class="fas fa-times ml-1"></i>إلغاء</a>
                        </div>
                    </div>
                </div>

                <!-- Collapsible Advanced Filters -->
                <div id="advancedFilters" class="px-6 py-4" style="display: <?php echo $advanced_filters_active ? 'block' : 'none'; ?>;">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 items-end">
                        
                        <!-- Sort By -->
                        <div>
                            <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                            <select name="sort_by" id="sort_by" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="display_order" <?php if ($sort_by == 'display_order') echo 'selected'; ?>>ترتيب العرض</option>
                                <option value="value_alpha" <?php if ($sort_by == 'value_alpha') echo 'selected'; ?>>القيمة بالأبجدية</option>
                                <option value="created_at" <?php if ($sort_by == 'created_at') echo 'selected'; ?>>تاريخ الإضافة</option>
                                <option value="updated_at" <?php if ($sort_by == 'updated_at') echo 'selected'; ?>>آخر تعديل</option>
                            </select>
                        </div>
                        <!-- Sort Direction -->
                        <div>
                            <label for="sort_dir" class="block text-sm font-medium text-gray-700 mb-1">الاتجاه</label>
                            <select name="sort_dir" id="sort_dir"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="ASC" <?php if ($sort_dir == 'ASC') echo 'selected'; ?>>تصاعدي</option>
                                <option value="DESC" <?php if ($sort_dir == 'DESC') echo 'selected'; ?>>تنازلي</option>
                            </select>
                        </div>
                        <!-- Status Filter -->
                        <div>
                            <label for="filter_status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                            <select name="filter_status" id="filter_status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="active" <?php if ($filter_status == 'active') echo 'selected'; ?>>نشط</option>
                                <option value="inactive" <?php if ($filter_status == 'inactive') echo 'selected'; ?>>معطل</option>
                                <option value="all" <?php if ($filter_status == 'all') echo 'selected'; ?>>الكل</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><i
                    class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><i
                    class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Attribute Values Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">القيمة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الترتيب</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإضافة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!$can_view): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-red-500">
                                    <i class="fas fa-times-circle text-4xl mb-4"></i>
                                    <p><?php echo $error_message; ?></p>
                                </td>
                            </tr>
                        <?php elseif (empty($attribute_values)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500"><i
                                        class="fas fa-grip-lines text-4xl mb-4 text-gray-300"></i>
                                    <p>لا توجد قيم مطابقة للبحث أو الفلترة لهذه السمة.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attribute_values as $value): ?>
                                <tr class="hover:bg-gray-50 <?php echo $value['is_active'] == 0 ? 'bg-red-50' : ''; ?>">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <div class="font-bold <?php echo $value['is_active'] == 0 ? 'text-red-600' : ''; ?>">
                                            <?php echo htmlspecialchars($value['value']); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php echo htmlspecialchars($value['display_order']); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php if ($value['is_active']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle ml-1"></i>نشط
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-ban ml-1"></i>معطل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                        <?php echo date('Y/m/d', strtotime($value['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-center">
                                        <div class="flex justify-center items-center space-x-2 space-x-reverse">
                                            <?php if ($can_edit): ?>
                                                <a href="edit.php?id=<?php echo $value['id']; ?>&attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                                                    class="text-green-600 hover:text-green-900" title="تعديل"><i
                                                        class="fas fa-edit"></i></a>
                                                <a href="?action=toggle_active&id=<?php echo $value['id']; ?>&status=<?php echo $value['is_active'] . '&attribute_id=' . htmlspecialchars($attribute_id) . '&' . http_build_query(array_filter($_GET, fn($k) => $k != 'action' && $k != 'id' && $k != 'status' && $k != 'attribute_id', ARRAY_FILTER_USE_KEY)); ?>"
                                                    class="<?php echo $value['is_active'] ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'; ?>"
                                                    title="<?php echo $value['is_active'] ? 'تعطيل' : 'تفعيل'; ?>"
                                                    onclick="return confirm('<?php echo $value['is_active'] ? 'هل أنت متأكد من تعطيل هذه القيمة؟' : 'هل أنت متأكد من تفعيل هذه القيمة؟'; ?>')">
                                                    <i class="fas fa-power-off"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($can_view && $total_pages > 1): ?>
                <div
                    class="bg-white px-4 py-3 flex flex-col sm:flex-row items-center justify-between border-t border-gray-200 sm:px-6">
                    <div class="w-full sm:w-auto text-center sm:text-right mb-4 sm:mb-0">
                        <p class="text-sm text-gray-700">
                            عرض <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى <span
                                class="font-medium"><?php echo min($offset + $limit, $total_attribute_values); ?></span>
                            من <span class="font-medium"><?php echo $total_attribute_values; ?></span> نتيجة
                        </p>
                    </div>
                    <div class="w-full sm:w-auto">
                        <nav class="relative z-0 inline-flex justify-center rounded-md shadow-sm -space-x-px w-full"
                            aria-label="Pagination">
                            <?php
                            $query_params = $_GET;
                            unset($query_params['page']);
                            
                            $base_url = '?' . http_build_query($query_params);

                            // Previous Page Link
                            $prev_page = $page - 1;
                            $prev_disabled = $page <= 1 ? 'pointer-events-none opacity-50' : '';
                            echo "<a href='{$base_url}&page={$prev_page}' class='relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 {$prev_disabled}'><span class='sr-only'>السابق</span><i class='fas fa-chevron-right'></i></a>";

                            // Page Number Links (Fixed Logic)
                            $max_links = 5;
                            $start_page = max(1, $page - floor($max_links / 2));
                            $end_page = min($total_pages, $page + floor($max_links / 2));

                            if ($end_page - $start_page + 1 < $max_links) {
                                if ($start_page == 1) {
                                    $end_page = min($total_pages, $start_page + $max_links - 1);
                                } elseif ($end_page == $total_pages) {
                                    $start_page = max(1, $total_pages - $max_links + 1);
                                }
                            }
                            
                            // First page and ellipsis
                            if ($start_page > 1) {
                                echo "<a href='{$base_url}&page=1' class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50'>1</a>";
                                if ($start_page > 2) {
                                    echo "<span class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700'>...</span>";
                                }
                                $start_page = max(2, $start_page); // Ensure start is at least 2 if 1 is printed
                            }


                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active_class = ($i == $page) ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50';
                                echo "<a href='{$base_url}&page={$i}' class='relative inline-flex items-center px-4 py-2 border text-sm font-medium {$active_class}'>{$i}</a>";
                            }

                            // Last page and ellipsis
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo "<span class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700'>...</span>";
                                }
                                echo "<a href='{$base_url}&page={$total_pages}' class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50'>{$total_pages}</a>";
                            }

                            // Next Page Link
                            $next_page = $page + 1;
                            $next_disabled = $page >= $total_pages ? 'pointer-events-none opacity-50' : '';
                            echo "<a href='{$base_url}&page={$next_page}' class='relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 {$next_disabled}'><span class='sr-only'>التالي</span><i class='fas fa-chevron-left'></i></a>";
                            ?>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleAdvancedFiltersBtn');
        const filtersDiv = document.getElementById('advancedFilters');
        const toggleText = document.getElementById('toggleText');
        
        // Use PHP variable for initial state
        const advancedFiltersActive = <?php echo $advanced_filters_active ? 'true' : 'false'; ?>;

        if (advancedFiltersActive) {
             filtersDiv.style.display = 'block';
             toggleText.textContent = 'إخفاء الفلاتر المتقدمة';
        } else {
             filtersDiv.style.display = 'none';
             toggleText.textContent = 'إظهار الفلاتر المتقدمة';
        }

        toggleBtn.addEventListener('click', function() {
            const isHidden = filtersDiv.style.display === 'none';
            if (isHidden) {
                filtersDiv.style.display = 'block';
                toggleText.textContent = 'إخفاء الفلاتر المتقدمة';
            } else {
                filtersDiv.style.display = 'none';
                toggleText.textContent = 'إظهار الفلاتر المتقدمة';
            }
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>