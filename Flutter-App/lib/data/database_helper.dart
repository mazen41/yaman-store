import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';

class ScanRecord {
  final int? id;
  final String sku;
  final int timestamp;
  final bool synced;
  final int selectedItemId;

  ScanRecord({
    this.id,
    required this.sku,
    required this.timestamp,
    this.synced = false,
    this.selectedItemId = 0,
  });

  Map<String, dynamic> toMap() => {
        'id': id,
        'sku': sku,
        'timestamp': timestamp,
        'synced': synced ? 1 : 0,
        'selected_item_id': selectedItemId,
      };

  factory ScanRecord.fromMap(Map<String, dynamic> map) => ScanRecord(
        id: map['id'],
        sku: map['sku'],
        timestamp: map['timestamp'],
        synced: map['synced'] == 1,
        selectedItemId: map['selected_item_id'] as int? ?? 0,
      );
}

class LocalOrderMatch {
  final int itemId;
  final int orderId;
  final String orderNumber;
  final String customerName;
  final String customerMobile;
  final String status;
  final bool isSorted;
  final String sku;
  final String productName;
  final String productImage;
  final int totalSkus;
  final int purchaseGroupId;
  final String purchaseGroupNumber;
  final String purchaseGroupName;

  LocalOrderMatch({
    required this.itemId,
    required this.orderId,
    required this.orderNumber,
    required this.customerName,
    required this.customerMobile,
    required this.status,
    required this.isSorted,
    required this.sku,
    required this.productName,
    required this.productImage,
    this.totalSkus = 0,
    this.purchaseGroupId = 0,
    this.purchaseGroupNumber = '',
    this.purchaseGroupName = '',
  });
}

class DatabaseHelper {
  static String _normalizeSku(String value) =>
      value.toUpperCase().replaceAll(RegExp(r"[-\s\u00A0\u200B\u200C\u200D]"), "");
  static final DatabaseHelper instance = DatabaseHelper._init();
  static Database? _database;

  DatabaseHelper._init();

  Future<Database> get database async {
    if (_database != null) return _database!;
    _database = await _initDB();
    return _database!;
  }

  Future<Database> _initDB() async {
    final path = join(await getDatabasesPath(), 'yaman_scanner.db');
    return openDatabase(path, version: 9, onCreate: (db, version) async {
      await _createTables(db);
    }, onUpgrade: (db, oldVersion, newVersion) async {
      if (oldVersion < 4) {
        await db.execute('''CREATE TABLE IF NOT EXISTS scan_records (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL, timestamp INTEGER NOT NULL, synced INTEGER NOT NULL DEFAULT 0, selected_item_id INTEGER NOT NULL DEFAULT 0, UNIQUE(sku, selected_item_id) ON CONFLICT IGNORE)''');
        await _createOrdersTables(db);
      }
      if (oldVersion < 5) {
        // Upgrade database to version 5
        // 1. Add productName and productImage fields to order_items_cache if not exists
        try {
          await db.execute('ALTER TABLE order_items_cache ADD COLUMN product_name TEXT DEFAULT ""');
        } catch (_) {}
        try {
          await db.execute('ALTER TABLE order_items_cache ADD COLUMN product_image TEXT DEFAULT ""');
        } catch (_) {}
        
        // 2. Create auxiliary tables
        await _createAuxiliaryTables(db);
      }
      if (oldVersion < 6) {
        try {
          await db.execute('ALTER TABLE orders_cache ADD COLUMN total_skus INTEGER NOT NULL DEFAULT 0');
        } catch (_) {}
      }
      if (oldVersion < 7) {
        try {
          await db.execute('ALTER TABLE orders_cache ADD COLUMN purchase_group_id INTEGER NOT NULL DEFAULT 0');
        } catch (_) {}
        try {
          await db.execute('ALTER TABLE orders_cache ADD COLUMN purchase_group_number TEXT NOT NULL DEFAULT ""');
        } catch (_) {}
      }
      if (oldVersion < 8) {
        try {
          await db.execute('ALTER TABLE orders_cache ADD COLUMN purchase_group_name TEXT NOT NULL DEFAULT ""');
        } catch (_) {}
      }
      if (oldVersion < 9) {
        try {
          await db.execute('ALTER TABLE order_items_cache ADD COLUMN sku_normalized TEXT NOT NULL DEFAULT ""');
        } catch (_) {}
        try {
          await db.execute("UPDATE order_items_cache SET sku_normalized = UPPER(REPLACE(REPLACE(REPLACE(TRIM(sku), '-', ''), ' ', ''), CHAR(9), ''))");
        } catch (_) {}
        await db.execute('CREATE INDEX IF NOT EXISTS idx_items_sku_normalized ON order_items_cache(sku_normalized)');
      }
    });
  }

  Future<void> _createTables(Database db) async {
    await db.execute('''CREATE TABLE scan_records (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL, timestamp INTEGER NOT NULL, synced INTEGER NOT NULL DEFAULT 0, selected_item_id INTEGER NOT NULL DEFAULT 0, UNIQUE(sku, selected_item_id) ON CONFLICT IGNORE)''');
    await _createOrdersTables(db);
    await _createAuxiliaryTables(db);
  }

  Future<void> _createOrdersTables(Database db) async {
    await db.execute('''CREATE TABLE IF NOT EXISTS orders_cache (
      order_id INTEGER PRIMARY KEY, 
      order_number TEXT, 
      customer_name TEXT, 
      customer_mobile TEXT, 
      status TEXT,
      total_skus INTEGER NOT NULL DEFAULT 0,
      purchase_group_id INTEGER NOT NULL DEFAULT 0,
      purchase_group_number TEXT NOT NULL DEFAULT "",
      purchase_group_name TEXT NOT NULL DEFAULT "",
      updated_at INTEGER NOT NULL
    )''');
    await db.execute('''CREATE TABLE IF NOT EXISTS order_items_cache (
      item_id INTEGER PRIMARY KEY, 
      order_id INTEGER NOT NULL, 
      sku TEXT NOT NULL, 
      sku_normalized TEXT NOT NULL DEFAULT "",
      is_sorted INTEGER NOT NULL DEFAULT 0, 
      product_name TEXT DEFAULT "",
      product_image TEXT DEFAULT "",
      FOREIGN KEY(order_id) REFERENCES orders_cache(order_id) ON DELETE CASCADE
    )''');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_items_sku ON order_items_cache(sku)');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_items_sku_normalized ON order_items_cache(sku_normalized)');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_items_unsorted ON order_items_cache(is_sorted)');
  }

  Future<void> _createAuxiliaryTables(Database db) async {
    await db.execute('''CREATE TABLE IF NOT EXISTS users_cache (
      user_id INTEGER PRIMARY KEY, 
      name TEXT, 
      role TEXT
    )''');
    await db.execute('''CREATE TABLE IF NOT EXISTS settings_cache (
      key TEXT PRIMARY KEY, 
      value TEXT
    )''');
    await db.execute('''CREATE TABLE IF NOT EXISTS sync_metadata (
      key TEXT PRIMARY KEY, 
      value TEXT
    )''');
  }

  // ── Sync Cache Operations ──────────────────────────────────────────

  Future<void> replaceOrdersCache(List<Map<String, dynamic>> orders, List<Map<String, dynamic>> items) async {
    final db = await database;
    final now = DateTime.now().millisecondsSinceEpoch;
    await db.transaction((txn) async {
      await txn.delete('order_items_cache');
      await txn.delete('orders_cache');
      for (final order in orders) {
        await txn.insert('orders_cache', {
          'order_id': order['order_id'],
          'order_number': order['order_number'],
          'customer_name': order['customer_name'],
          'customer_mobile': order['customer_mobile'],
          'status': order['status'],
          'total_skus': order['total_skus'] ?? 0,
          'purchase_group_id': order['purchase_group_id'] ?? 0, // Direct assignment only
          'purchase_group_number': order['purchase_group_number'] ?? '',
          'purchase_group_name': order['purchase_group_name'] ?? '',
          'updated_at': order['updated_at'] ?? now,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
      for (final item in items) {
        await txn.insert('order_items_cache', {
          'item_id': item['item_id'],
          'order_id': item['order_id'],
          'sku': item['sku'],
          'sku_normalized': _normalizeSku((item['sku'] ?? '').toString()),
          'is_sorted': item['is_sorted'],
          'product_name': item['product_name'] ?? '',
          'product_image': item['product_image'] ?? '',
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    });
  }

  Future<void> syncOrdersIncremental(List<Map<String, dynamic>> orders, List<Map<String, dynamic>> items) async {
    if (orders.isEmpty && items.isEmpty) return;
    
    final db = await database;
    final now = DateTime.now().millisecondsSinceEpoch;
    
    // Collect order IDs to delete for batch deletion
    final orderIdsToDelete = <int>[];
    
    await db.transaction((txn) async {
      for (final order in orders) {
        final orderId = order['order_id'] as int;
        final status = (order['status'] ?? '').toString().toLowerCase();

        // If the order status is inactive, mark for deletion to save space
        if (['cancelled', 'delivered', 'returned', 'refunded'].contains(status)) {
          orderIdsToDelete.add(orderId);
        } else {
          // Otherwise, upsert order with direct purchase group assignment only
          await txn.insert('orders_cache', {
            'order_id': order['order_id'],
            'order_number': order['order_number'],
            'customer_name': order['customer_name'],
            'customer_mobile': order['customer_mobile'],
            'status': order['status'],
            'total_skus': order['total_skus'] ?? 0,
            'purchase_group_id': order['purchase_group_id'] ?? 0, // Direct assignment only
            'purchase_group_number': order['purchase_group_number'] ?? '',
            'purchase_group_name': order['purchase_group_name'] ?? '',
            'updated_at': order['updated_at'] ?? now,
          }, conflictAlgorithm: ConflictAlgorithm.replace);
        }
      }

      // Batch delete inactive orders
      if (orderIdsToDelete.isNotEmpty) {
        for (final orderId in orderIdsToDelete) {
          await txn.delete('order_items_cache', where: 'order_id = ?', whereArgs: [orderId]);
          await txn.delete('orders_cache', where: 'order_id = ?', whereArgs: [orderId]);
        }
      }

      // Get all existing order IDs in a single query for better performance
      final existingOrders = await txn.query('orders_cache', columns: ['order_id']);
      final existingOrderIds = existingOrders.map((row) => row['order_id'] as int).toSet();

      for (final item in items) {
        final orderId = item['order_id'] as int;
        
        // Only insert item if its parent order exists in cache
        if (existingOrderIds.contains(orderId)) {
          await txn.insert('order_items_cache', {
            'item_id': item['item_id'],
            'order_id': item['order_id'],
            'sku': item['sku'],
            'sku_normalized': _normalizeSku((item['sku'] ?? '').toString()),
            'is_sorted': item['is_sorted'],
            'product_name': item['product_name'] ?? '',
            'product_image': item['product_image'] ?? '',
          }, conflictAlgorithm: ConflictAlgorithm.replace);
        }
      }
    });
  }

  Future<List<LocalOrderMatch>> findOrdersBySku(String sku, {bool sorted = false, int? purchaseGroupId}) async {
    final db = await database;
    String query = '''
      SELECT i.item_id, i.order_id, i.sku, i.is_sorted, i.product_name, i.product_image,
             o.order_number, o.customer_name, o.customer_mobile, o.status AS order_status,
             o.total_skus, o.purchase_group_id, o.purchase_group_number, o.purchase_group_name
      FROM order_items_cache i
      JOIN orders_cache o ON o.order_id = i.order_id
      WHERE i.sku_normalized = ?
        AND COALESCE(i.is_sorted, 0) = ?
        AND LOWER(COALESCE(o.status, '')) NOT IN ('delivered', 'cancelled', 'returned', 'refunded', 'completed')
    ''';
    
    List<dynamic> args = [_normalizeSku(sku), sorted ? 1 : 0];
    
    // Add purchase group filter if specified (direct assignment only, no fallback)
    if (purchaseGroupId != null && purchaseGroupId > 0) {
      query += ' AND o.purchase_group_id = ?';
      args.add(purchaseGroupId);
    }
    
    final rows = await db.rawQuery(query, args);
    
    return rows.map((r) => LocalOrderMatch(
      itemId: r['item_id'] as int, 
      orderId: r['order_id'] as int, 
      sku: (r['sku'] ?? '').toString(), 
      orderNumber: (r['order_number'] ?? '').toString(), 
      customerName: (r['customer_name'] ?? '').toString(), 
      customerMobile: (r['customer_mobile'] ?? '').toString(), 
      status: ((r['is_sorted'] as int? ?? 0) == 1) ? 'scanned' : 'pending',
      isSorted: (r['is_sorted'] as int? ?? 0) == 1,
      productName: (r['product_name'] ?? '').toString(),
      productImage: (r['product_image'] ?? '').toString(),
      totalSkus: r['total_skus'] as int? ?? 0,
      purchaseGroupId: r['purchase_group_id'] as int? ?? 0,
      purchaseGroupNumber: (r['purchase_group_number'] ?? '').toString(),
      purchaseGroupName: (r['purchase_group_name'] ?? '').toString(),
    )).toList();
  }

  Future<List<LocalOrderMatch>> findUnsortedOrdersBySku(String sku, {int? purchaseGroupId}) async {
    final db = await database;
    String query = '''
      SELECT i.item_id, i.order_id, i.sku, i.is_sorted, i.product_name, i.product_image,
             o.order_number, o.customer_name, o.customer_mobile, o.status AS order_status,
             o.total_skus, o.purchase_group_id, o.purchase_group_number, o.purchase_group_name
      FROM order_items_cache i
      JOIN orders_cache o ON o.order_id = i.order_id
      WHERE i.sku_normalized = ?
        AND COALESCE(i.is_sorted, 0) = 0
        AND LOWER(COALESCE(o.status, '')) NOT IN ('delivered', 'cancelled', 'returned', 'refunded', 'completed')
    ''';
    
    List<dynamic> args = [_normalizeSku(sku)];
    
    // Add purchase group filter if specified (direct assignment only, no fallback)
    if (purchaseGroupId != null && purchaseGroupId > 0) {
      query += ' AND o.purchase_group_id = ?';
      args.add(purchaseGroupId);
    }
    
    final rows = await db.rawQuery(query, args);

    return rows.map((r) => LocalOrderMatch(
      itemId: r['item_id'] as int,
      orderId: r['order_id'] as int,
      sku: (r['sku'] ?? '').toString(),
      orderNumber: (r['order_number'] ?? '').toString(),
      customerName: (r['customer_name'] ?? '').toString(),
      customerMobile: (r['customer_mobile'] ?? '').toString(),
      status: ((r['is_sorted'] as int? ?? 0) == 1) ? 'scanned' : 'pending',
      isSorted: (r['is_sorted'] as int? ?? 0) == 1,
      productName: (r['product_name'] ?? '').toString(),
      productImage: (r['product_image'] ?? '').toString(),
      totalSkus: r['total_skus'] as int? ?? 0,
      purchaseGroupId: r['purchase_group_id'] as int? ?? 0,
      purchaseGroupNumber: (r['purchase_group_number'] ?? '').toString(),
      purchaseGroupName: (r['purchase_group_name'] ?? '').toString(),
    )).toList();
  }

  Future<bool> hasAnySkuMatch(String sku) async {
    final db = await database;
    final result = await db.rawQuery('''
      SELECT COUNT(*) AS c
      FROM order_items_cache
      WHERE sku_normalized = ?
    ''', [_normalizeSku(sku)]);
    return (result.first['c'] as int? ?? 0) > 0;
  }

  Future<void> markItemSorted(int itemId) async {
    final db = await database;
    await db.update('order_items_cache', {'is_sorted': 1}, where: 'item_id = ?', whereArgs: [itemId]);
  }

  Future<void> markItemUnsorted(int itemId) async {
    final db = await database;
    await db.update('order_items_cache', {'is_sorted': 0}, where: 'item_id = ?', whereArgs: [itemId]);
  }

  Future<int> countCachedItems() async {
    final db = await database;
    final result = await db.rawQuery('SELECT COUNT(*) c FROM order_items_cache');
    return (result.first['c'] as int?) ?? 0;
  }

  // ── Sync Metadata Helpers ──────────────────────────────────────────

  Future<String?> getMetadata(String key) async {
    final db = await database;
    final rows = await db.query('sync_metadata', where: 'key = ?', whereArgs: [key], limit: 1);
    if (rows.isEmpty) return null;
    return rows.first['value'] as String?;
  }

  Future<void> setMetadata(String key, String value) async {
    final db = await database;
    await db.insert('sync_metadata', {
      'key': key,
      'value': value
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  // ── Cached User Info Helpers ───────────────────────────────────────

  Future<void> cacheUser(int userId, String name, String role) async {
    final db = await database;
    await db.insert('users_cache', {
      'user_id': userId,
      'name': name,
      'role': role
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, String>?> getCachedUser() async {
    final db = await database;
    final rows = await db.query('users_cache', limit: 1);
    if (rows.isEmpty) return null;
    final row = rows.first;
    return {
      'user_id': row['user_id'].toString(),
      'name': (row['name'] ?? '').toString(),
      'role': (row['role'] ?? '').toString(),
    };
  }

  Future<void> clearCachedUser() async {
    final db = await database;
    await db.delete('users_cache');
  }

  // ── Pending Local Scans Queue ──────────────────────────────────────

  Future<void> insertScan(ScanRecord record) async { 
    final db = await database; 
    await db.insert('scan_records', record.toMap()..remove('id'), conflictAlgorithm: ConflictAlgorithm.ignore); 
  }
  
  Future<void> updateSelectedItemId(int id, int selectedItemId) async { 
    final db = await database; 
    await db.update('scan_records', {'selected_item_id': selectedItemId}, where: 'id = ?', whereArgs: [id]); 
  }
  
  Future<void> markSynced(int id) async { 
    final db = await database; 
    await db.update('scan_records', {'synced': 1}, where: 'id = ?', whereArgs: [id]); 
  }
  
  Future<void> deleteSynced() async { 
    final db = await database; 
    await db.delete('scan_records', where: 'synced = ?', whereArgs: [1]); 
  }
  
  Future<List<ScanRecord>> getUnsynced() async { 
    final db = await database; 
    final maps = await db.query('scan_records', where: 'synced = ?', whereArgs: [0], orderBy: 'timestamp ASC'); 
    return maps.map(ScanRecord.fromMap).toList(); 
  }
  
  Future<ScanRecord?> getUnsyncedBySku(String sku) async { 
    final db = await database; 
    final maps = await db.query('scan_records', where: 'sku = ? AND synced = ?', whereArgs: [sku, 0], limit: 1); 
    if (maps.isEmpty) return null; 
    return ScanRecord.fromMap(maps.first); 
  }
  
  Future<int> countUnsynced() async { 
    final db = await database; 
    final result = await db.rawQuery('SELECT COUNT(*) AS c FROM scan_records WHERE synced = 0'); 
    return result.first['c'] as int; 
  }

  Future<List<Map<String, String>>> searchSkusByPrefix(String prefix) async {
    final db = await database;
    final rows = await db.rawQuery('''
      SELECT DISTINCT i.sku, o.order_number, o.customer_name
      FROM order_items_cache i
      JOIN orders_cache o ON o.order_id = i.order_id
      WHERE i.sku LIKE ?
      ORDER BY i.sku
      LIMIT 20
    ''', ['${prefix.toUpperCase()}%']);
    return rows.map((r) => {
      'sku': (r['sku'] ?? '').toString(),
      'order_number': (r['order_number'] ?? '').toString(),
      'customer_name': (r['customer_name'] ?? '').toString(),
    }).where((m) => m['sku']!.isNotEmpty).toList();
  }
}
