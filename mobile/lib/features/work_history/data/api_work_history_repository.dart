import 'package:flutter/foundation.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../domain/work_history_models.dart';
import 'work_history_repository.dart';

class ApiWorkHistoryRepository implements WorkHistoryRepository {
  ApiWorkHistoryRepository(this._client);
  final ApiClient _client;

  @override
  Future<List<WorkOrderHistoryItem>> fetchHistory() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.staffWorkHistory),
      (data) {
        final root =
            data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
        final rows = root['work_orders'] is List
            ? root['work_orders'] as List
            : const [];
        final items = <WorkOrderHistoryItem>[];
        for (final item in rows) {
          if (item is! Map) continue;
          final raw = Map<String, dynamic>.from(item);
          try {
            final parsed = WorkOrderHistoryItem.fromJson(raw);
            items.add(parsed);
            // Temporary, safe (no tokens/PII) instrumentation for the
            // real-device "Before/After images not showing" investigation —
            // per the task's explicit request, ground-truths whether the
            // problem is the backend response (rawImageCount is 0/wrong)
            // or Flutter rendering (rawImageCount > 0 but photosParsed is
            // 0, or the URLs look wrong). debugPrint is NOT stripped from
            // release builds — check `adb logcat | grep flutter` on the
            // real device. Remove once confirmed fixed on-device.
            final rawImages = raw['images'];
            final rawImageCount = rawImages is List ? rawImages.length : -1;
            final before = parsed.photosOfType('Before');
            final after = parsed.photosOfType('After');
            debugPrint(
              '[cpms.work_history] work_order_id=${parsed.id} '
              'reference=${parsed.reference} '
              'verification_status=${parsed.verificationStatus} '
              'raw_images_in_json=$rawImageCount '
              'parsed_photos_total=${parsed.photos.length} '
              'before_count=${before.length} after_count=${after.length} '
              'before_urls=${before.map((p) => p.url).toList()} '
              'after_urls=${after.map((p) => p.url).toList()}',
            );
          } catch (e) {
            // One malformed work order must never take down the whole
            // Work Order History screen — but log which one and why,
            // instead of silently dropping it (a parse exception inside
            // WorkHistoryPhoto.fromJson would previously vanish an entire
            // record with zero trace).
            debugPrint(
                '[cpms.work_history] FAILED to parse work order row: $raw — $e');
            continue;
          }
        }
        debugPrint(
            '[cpms.work_history] fetchHistory: ${rows.length} raw rows -> '
            '${items.length} parsed items');
        return items;
      },
    );
  }
}
