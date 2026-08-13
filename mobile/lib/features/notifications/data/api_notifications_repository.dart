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
      (data) => ((data as Map<String, dynamic>)['notifications'] as List<dynamic>).map((e) {
        final json = e as Map<String, dynamic>;
        return AppNotification(
          id: json['id'] as String,
          type: NotificationType.values.firstWhere((t) => t.name == json['type'], orElse: () => NotificationType.managementAnnouncement),
          title: json['title'] as String,
          body: json['body'] as String,
          createdAt: DateTime.parse(json['created_at'] as String),
          deepLinkRoute: json['deep_link_route'] as String?,
          isRead: json['is_read'] as bool? ?? false,
        );
      }).toList(),
    );
  }

  @override
  Future<void> markRead(String id) {
    return _client.request((dio) => dio.post(ApiEndpoints.notificationRead(id)), (_) {});
  }
}
