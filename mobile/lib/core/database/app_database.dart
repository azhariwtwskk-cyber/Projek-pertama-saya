import 'dart:convert';

import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart';

/// Local offline store backing sections 26/27 (Offline Mode + Sync Centre).
///
/// Two tables:
///  - `cache`: last-known-good JSON blobs (profile, today's tasks, task
///    details, before-photos, PM checklists) keyed by a logical key, so the
///    app has something to show immediately on a cold, offline start.
///  - `pending_sync_items`: a durable outbox. Every mutation made while
///    offline (evidence photo, daily work entry, task status update) is
///    written here BEFORE the UI reports success, and is only deleted once
///    the server has acknowledged it. This is what guarantees staff never
///    lose a photo to a dropped connection.
class AppDatabase {
  AppDatabase._();
  static final AppDatabase instance = AppDatabase._();

  Database? _db;

  Future<Database> get database async {
    _db ??= await _open();
    return _db!;
  }

  Future<Database> _open() async {
    final dir = await getDatabasesPath();
    final path = join(dir, 'cpmspro_workforce.db');
    return openDatabase(
      path,
      version: 1,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE cache (
            cache_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
          )
        ''');
        await db.execute('''
          CREATE TABLE pending_sync_items (
            id TEXT PRIMARY KEY,
            item_type TEXT NOT NULL,
            summary TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            method TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            file_paths_json TEXT,
            created_at TEXT NOT NULL,
            retry_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT
          )
        ''');
      },
    );
  }

  // ---- cache ----

  Future<void> putCache(String key, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
      'cache',
      {
        'cache_key': key,
        'payload': jsonEncode(payload),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCache(String key) async {
    final db = await database;
    final rows = await db.query('cache', where: 'cache_key = ?', whereArgs: [key], limit: 1);
    if (rows.isEmpty) return null;
    return jsonDecode(rows.first['payload'] as String) as Map<String, dynamic>;
  }

  // ---- pending sync outbox ----

  Future<void> enqueue(PendingSyncItem item) async {
    final db = await database;
    await db.insert('pending_sync_items', item.toRow());
  }

  Future<List<PendingSyncItem>> pendingItems() async {
    final db = await database;
    final rows = await db.query('pending_sync_items', orderBy: 'created_at ASC');
    return rows.map(PendingSyncItem.fromRow).toList();
  }

  Future<void> markSynced(String id) async {
    final db = await database;
    await db.delete('pending_sync_items', where: 'id = ?', whereArgs: [id]);
  }

  Future<void> recordFailure(String id, String error) async {
    final db = await database;
    await db.rawUpdate(
      'UPDATE pending_sync_items SET retry_count = retry_count + 1, last_error = ? WHERE id = ?',
      [error, id],
    );
  }
}

enum PendingSyncType { photoEvidence, dailyWork, taskUpdate, pmCompletion, attendance }

class PendingSyncItem {
  const PendingSyncItem({
    required this.id,
    required this.type,
    required this.summary,
    required this.endpoint,
    required this.method,
    required this.payload,
    required this.createdAt,
    this.filePaths = const [],
    this.retryCount = 0,
    this.lastError,
  });

  final String id;
  final PendingSyncType type;
  final String summary;
  final String endpoint;
  final String method;
  final Map<String, dynamic> payload;
  final List<String> filePaths;
  final DateTime createdAt;
  final int retryCount;
  final String? lastError;

  Map<String, dynamic> toRow() => {
        'id': id,
        'item_type': type.name,
        'summary': summary,
        'endpoint': endpoint,
        'method': method,
        'payload_json': jsonEncode(payload),
        'file_paths_json': jsonEncode(filePaths),
        'created_at': createdAt.toIso8601String(),
        'retry_count': retryCount,
        'last_error': lastError,
      };

  factory PendingSyncItem.fromRow(Map<String, dynamic> row) => PendingSyncItem(
        id: row['id'] as String,
        type: PendingSyncType.values.firstWhere((t) => t.name == row['item_type']),
        summary: row['summary'] as String,
        endpoint: row['endpoint'] as String,
        method: row['method'] as String,
        payload: jsonDecode(row['payload_json'] as String) as Map<String, dynamic>,
        filePaths: (jsonDecode(row['file_paths_json'] as String? ?? '[]') as List).cast<String>(),
        createdAt: DateTime.parse(row['created_at'] as String),
        retryCount: row['retry_count'] as int? ?? 0,
        lastError: row['last_error'] as String?,
      );
}
