import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';

class ScanRecord {
  final int? id;
  final String sku;
  final int timestamp;
  final bool synced;
  /// If non-zero, this is the specific item_id the user already chose
  /// (stored when the order-picker was shown offline — future-use).
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
    return openDatabase(
      path,
      version: 2,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE scan_records (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            sku               TEXT NOT NULL,
            timestamp         INTEGER NOT NULL,
            synced            INTEGER NOT NULL DEFAULT 0,
            selected_item_id  INTEGER NOT NULL DEFAULT 0,
            UNIQUE(sku) ON CONFLICT IGNORE
          )
        ''');
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          // Add selected_item_id column to existing installs
          try {
            await db.execute(
              'ALTER TABLE scan_records ADD COLUMN selected_item_id INTEGER NOT NULL DEFAULT 0',
            );
          } catch (_) {
            // Column may already exist on a re-install — ignore
          }
        }
      },
    );
  }

  // ── Writes ──────────────────────────────────────────────────────────────

  /// Insert a new offline scan.
  /// Uses IGNORE conflict so duplicate SKUs are silently skipped at DB level.
  Future<void> insertScan(ScanRecord record) async {
    final db = await database;
    await db.insert(
      'scan_records',
      record.toMap()..remove('id'),
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  /// Update the selected_item_id for an existing record (e.g. after user
  /// resolves a multi-order conflict during sync).
  Future<void> updateSelectedItemId(int id, int selectedItemId) async {
    final db = await database;
    await db.update(
      'scan_records',
      {'selected_item_id': selectedItemId},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<void> markSynced(int id) async {
    final db = await database;
    await db.update(
      'scan_records',
      {'synced': 1},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<void> deleteSynced() async {
    final db = await database;
    await db.delete('scan_records', where: 'synced = ?', whereArgs: [1]);
  }

  // ── Reads ────────────────────────────────────────────────────────────────

  Future<List<ScanRecord>> getUnsynced() async {
    final db = await database;
    final maps = await db.query(
      'scan_records',
      where: 'synced = ?',
      whereArgs: [0],
      orderBy: 'timestamp ASC',
    );
    return maps.map(ScanRecord.fromMap).toList();
  }

  /// Returns the first unsynced record for [sku], or null if none exists.
  Future<ScanRecord?> getUnsyncedBySku(String sku) async {
    final db = await database;
    final maps = await db.query(
      'scan_records',
      where: 'sku = ? AND synced = ?',
      whereArgs: [sku, 0],
      limit: 1,
    );
    if (maps.isEmpty) return null;
    return ScanRecord.fromMap(maps.first);
  }

  Future<int> countUnsynced() async {
    final db = await database;
    final result = await db.rawQuery(
      'SELECT COUNT(*) AS c FROM scan_records WHERE synced = 0',
    );
    return result.first['c'] as int;
  }
}
