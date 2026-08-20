import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../domain/notification_models.dart';
import 'notifications_repository.dart';

class ApiNotificationsRepository implements NotificationsRepository {
  ApiNotificationsRepository(this._client);
  final ApiClient _client;

  @override
  Future<List<AppNotification>> fetchNotifications() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.notifications),
      (data) {
        final root =
            data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
        final rows = root['notifications'] is List
            ? root['notifications'] as List
            : const [];
        final items = <AppNotification>[];
        for (final item in rows) {
          if (item is! Map) continue;
          try {
            final json = Map<String, dynamic>.from(item);
            final rawType = (json['type'] ?? '').toString();
            items.add(AppNotification(
              id: (json['id'] ?? '').toString(),
              // The real `notification_type` column values (e.g.
              // `work_order`, `announcement`) don't line up 1:1 with this
              // enum's camelCase names — falling back to a generic
              // announcement icon/label for anything unrecognised is
              // intentional, not a bug, so one new backend type can never
              // crash the Alerts screen.
              type: NotificationType.values.firstWhere(
                (t) => t.name.toLowerCase() == rawType.toLowerCase(),
                orElse: () => NotificationType.managementAnnouncement,
              ),
              title: (json['title'] ?? '').toString(),
              body: (json['body'] ?? json['message'] ?? '').toString(),
              createdAt:
                  DateTime.tryParse((json['created_at'] ?? '').toString()) ??
                      DateTime.now(),
              // `action_url` on the real backend is a web-portal URL, not
              // a Flutter route — never push it directly as a route.
              deepLinkRoute: null,
              isRead: json['is_read'] == true ||
                  json['is_read'] == 1 ||
                  json['is_read'] == '1',
            ));
          } catch (_) {
            // One malformed notification row must never take down the
            // whole Alerts screen.
            continue;
          }
        }
        return items;
      },
    );
  }

  @override
  Future<void> markRead(String id) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.notificationMarkRead, data: {'id': id}),
      (_) {},
    );
  }

  @override
  Future<void> markAllRead() {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.notificationMarkAllRead),
      (_) {},
    );
  }
}
