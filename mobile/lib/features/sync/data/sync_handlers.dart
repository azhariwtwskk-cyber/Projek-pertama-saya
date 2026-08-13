import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/database/app_database.dart';
import '../../auth/application/auth_providers.dart';

/// Replays a single [PendingSyncItem] against the real CPMSPro API. Works
/// generically off the endpoint/method/payload/files captured at enqueue
/// time, so the sync queue never needs to know which feature produced the
/// item — a task evidence photo, a daily work entry and an attendance
/// clock-in all flow through the same replay path.
class SyncHandlers {
  const SyncHandlers._();

  static Future<void> dispatch(Ref ref, PendingSyncItem item) async {
    if (AppConfig.useMockApi) {
      // No live backend to replay against in mock/demo mode: simulate the
      // round-trip latency so the Sync Centre UX (spinner -> synced) is
      // still real, then mark it delivered.
      await Future.delayed(const Duration(milliseconds: 600));
      return;
    }

    final client = ref.read(apiClientProvider);
    await client.request((dio) async {
      if (item.filePaths.isNotEmpty) {
        final formData = FormData.fromMap({
          ...item.payload,
          for (var i = 0; i < item.filePaths.length; i++)
            'files[$i]': await MultipartFile.fromFile(item.filePaths[i]),
        });
        return dio.request(item.endpoint, data: formData, options: Options(method: item.method));
      }
      return dio.request(item.endpoint, data: item.payload, options: Options(method: item.method));
    }, (_) => null);
  }
}
