import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';

class ScanRecord {
  final int? id;
  final String sku;
  final int timestamp;
  final bool synced;

  ScanRecord({
    this.id,
    required this.sku,
    required this.timestamp,
    this.synced = false,
  });

  Map<String, dynamic> toMap() => {
        'id': id,
        'sku': sku,
        'timestamp': timestamp,
        'synced': synced ? 1 : 0,
      };

  factory ScanRecord.fromMap(Map<String, dynamic> map) => ScanRecord(
        id: map['id'],
        sku: map['sku'],
        timestamp: map['timestamp'],
        synced: map['synced'] == 1,
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
    return openDatabase(path, version: 1, onCreate: (db, version) async {
      await db.execute('''
        CREATE TABLE scan_records (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          sku TEXT NOT NULL,
          timestamp INTEGER NOT NULL,
          synced INTEGER NOT NULL DEFAULT 0
        )
      ''');
    });
  }

  Future<void> insertScan(ScanRecord record) async {
    final db = await database;
    await db.insert('scan_records', record.toMap(),
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<ScanRecord>> getUnsynced() async {
    final db = await database;
    final maps =
        await db.query('scan_records', where: 'synced = ?', whereArgs: [0]);
    return maps.map(ScanRecord.fromMap).toList();
  }

  Future<void> markSynced(int id) async {
    final db = await database;
    await db.update('scan_records', {'synced': 1},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<int> countUnsynced() async {
    final db = await database;
    final result =
        await db.rawQuery('SELECT COUNT(*) as c FROM scan_records WHERE synced=0');
    return result.first['c'] as int;
  }
}
