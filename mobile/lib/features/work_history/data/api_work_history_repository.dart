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
          try {
            items.add(
                WorkOrderHistoryItem.fromJson(Map<String, dynamic>.from(item)));
          } catch (_) {
            // One malformed work order must never take down the whole
            // Work Order History screen.
            continue;
          }
        }
        return items;
      },
    );
  }
}
