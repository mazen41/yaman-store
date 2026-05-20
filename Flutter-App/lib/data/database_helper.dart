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
  final String sku;

  LocalOrderMatch({required this.itemId, required this.orderId, required this.orderNumber, required this.customerName, required this.customerMobile, required this.status, required this.sku});
}

class DatabaseHelper {
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
    return openDatabase(path, version: 4, onCreate: (db, version) async {
      await _createTables(db);
    }, onUpgrade: (db, oldVersion, newVersion) async {
      if (oldVersion < 4) {
        await _createOrdersTables(db);
      }
    });
  }

  Future<void> _createTables(Database db) async {
    await db.execute('''CREATE TABLE scan_records (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL, timestamp INTEGER NOT NULL, synced INTEGER NOT NULL DEFAULT 0, selected_item_id INTEGER NOT NULL DEFAULT 0, UNIQUE(sku, selected_item_id) ON CONFLICT IGNORE)''');
    await _createOrdersTables(db);
  }

  Future<void> _createOrdersTables(Database db) async {
    await db.execute('''CREATE TABLE IF NOT EXISTS orders_cache (order_id INTEGER PRIMARY KEY, order_number TEXT, customer_name TEXT, customer_mobile TEXT, status TEXT, updated_at INTEGER NOT NULL)''');
    await db.execute('''CREATE TABLE IF NOT EXISTS order_items_cache (item_id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL, is_sorted INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(order_id) REFERENCES orders_cache(order_id) ON DELETE CASCADE)''');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_items_sku ON order_items_cache(sku)');
  }

  Future<void> replaceOrdersCache(List<Map<String, dynamic>> orders, List<Map<String, dynamic>> items) async {
    final db = await database;
    final now = DateTime.now().millisecondsSinceEpoch;
    await db.transaction((txn) async {
      await txn.delete('order_items_cache');
      await txn.delete('orders_cache');
      for (final order in orders) {
        await txn.insert('orders_cache', {...order, 'updated_at': now});
      }
      for (final item in items) {
        await txn.insert('order_items_cache', item);
      }
    });
  }

  Future<List<LocalOrderMatch>> findOrdersBySku(String sku) async {
    final db = await database;
    final rows = await db.rawQuery('''
      SELECT i.item_id, i.order_id, i.sku, o.order_number, o.customer_name, o.customer_mobile, o.status
      FROM order_items_cache i
      JOIN orders_cache o ON o.order_id = i.order_id
      WHERE UPPER(REPLACE(i.sku, '-', '')) = ? AND i.is_sorted = 0
    ''', [sku.toUpperCase().replaceAll('-', '')]);
    return rows.map((r) => LocalOrderMatch(itemId: r['item_id'] as int, orderId: r['order_id'] as int, sku: (r['sku'] ?? '').toString(), orderNumber: (r['order_number'] ?? '').toString(), customerName: (r['customer_name'] ?? '').toString(), customerMobile: (r['customer_mobile'] ?? '').toString(), status: (r['status'] ?? '').toString())).toList();
  }

  Future<void> markItemSorted(int itemId) async {
    final db = await database;
    await db.update('order_items_cache', {'is_sorted': 1}, where: 'item_id = ?', whereArgs: [itemId]);
  }

  Future<int> countCachedItems() async {
    final db = await database;
    final result = await db.rawQuery('SELECT COUNT(*) c FROM order_items_cache');
    return (result.first['c'] as int?) ?? 0;
  }

  Future<void> insertScan(ScanRecord record) async { final db = await database; await db.insert('scan_records', record.toMap()..remove('id'), conflictAlgorithm: ConflictAlgorithm.ignore); }
  Future<void> updateSelectedItemId(int id, int selectedItemId) async { final db = await database; await db.update('scan_records', {'selected_item_id': selectedItemId}, where: 'id = ?', whereArgs: [id]); }
  Future<void> markSynced(int id) async { final db = await database; await db.update('scan_records', {'synced': 1}, where: 'id = ?', whereArgs: [id]); }
  Future<void> deleteSynced() async { final db = await database; await db.delete('scan_records', where: 'synced = ?', whereArgs: [1]); }
  Future<List<ScanRecord>> getUnsynced() async { final db = await database; final maps = await db.query('scan_records', where: 'synced = ?', whereArgs: [0], orderBy: 'timestamp ASC'); return maps.map(ScanRecord.fromMap).toList(); }
  Future<ScanRecord?> getUnsyncedBySku(String sku) async { final db = await database; final maps = await db.query('scan_records', where: 'sku = ? AND synced = ?', whereArgs: [sku, 0], limit: 1); if (maps.isEmpty) return null; return ScanRecord.fromMap(maps.first); }
  Future<int> countUnsynced() async { final db = await database; final result = await db.rawQuery('SELECT COUNT(*) AS c FROM scan_records WHERE synced = 0'); return result.first['c'] as int; }
}
