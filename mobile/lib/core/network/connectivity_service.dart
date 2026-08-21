import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Single source of truth for "are we online right now". Repositories and
/// the sync queue watch this instead of polling, so evidence uploads and
/// task updates retry automatically the instant connectivity returns
/// (section 26/27), without the UI ever losing staff photos.
class ConnectivityService {
  ConnectivityService() {
    _sub = Connectivity().onConnectivityChanged.listen((results) {
      _controller.add(_isOnline(results));
    });
    Connectivity()
        .checkConnectivity()
        .then((r) => _controller.add(_isOnline(r)));
  }

  final _controller = StreamController<bool>.broadcast();
  StreamSubscription<List<ConnectivityResult>>? _sub;

  Stream<bool> get onStatusChange => _controller.stream;

  Future<bool> get isOnline async {
    final result = await Connectivity().checkConnectivity();
    return _isOnline(result);
  }

  bool _isOnline(List<ConnectivityResult> results) =>
      results.any((r) => r != ConnectivityResult.none);

  void dispose() {
    _sub?.cancel();
    _controller.close();
  }
}

final connectivityServiceProvider = Provider<ConnectivityService>((ref) {
  final service = ConnectivityService();
  ref.onDispose(service.dispose);
  return service;
});

final isOnlineProvider = StreamProvider<bool>((ref) {
  final service = ref.watch(connectivityServiceProvider);
  return service.onStatusChange;
});
