import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/database/app_database.dart';
import '../../../core/network/connectivity_service.dart';
import '../data/sync_handlers.dart';

/// Drives the offline outbox described in sections 26/27: holds whatever is
/// currently in [AppDatabase.pendingItems], and drains it the moment
/// connectivity returns (or when the user taps "Retry Sync").
class SyncQueueController extends StateNotifier<List<PendingSyncItem>> {
  SyncQueueController(this._ref) : super(const []) {
    refresh();
    _connectivitySub = _ref.read(connectivityServiceProvider).onStatusChange.listen((online) {
      if (online) retrySync();
    });
  }

  final Ref _ref;
  StreamSubscription<bool>? _connectivitySub;
  bool _syncing = false;
  DateTime? lastSyncedAt;

  Future<void> refresh() async {
    state = await AppDatabase.instance.pendingItems();
  }

  Future<void> enqueue(PendingSyncItem item) async {
    await AppDatabase.instance.enqueue(item);
    await refresh();
    final online = await _ref.read(connectivityServiceProvider).isOnline;
    if (online) retrySync();
  }

  Future<void> retrySync() async {
    if (_syncing) return;
    _syncing = true;
    try {
      final items = await AppDatabase.instance.pendingItems();
      for (final item in items) {
        try {
          await SyncHandlers.dispatch(_ref, item);
          await AppDatabase.instance.markSynced(item.id);
        } catch (e) {
          await AppDatabase.instance.recordFailure(item.id, e.toString());
        }
      }
      lastSyncedAt = DateTime.now();
    } finally {
      _syncing = false;
      await refresh();
    }
  }

  @override
  void dispose() {
    _connectivitySub?.cancel();
    super.dispose();
  }
}

final syncQueueControllerProvider = StateNotifierProvider<SyncQueueController, List<PendingSyncItem>>((ref) {
  return SyncQueueController(ref);
});

final pendingSyncCountProvider = Provider<int>((ref) => ref.watch(syncQueueControllerProvider).length);
