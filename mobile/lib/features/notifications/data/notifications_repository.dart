import '../domain/notification_models.dart';

abstract class NotificationsRepository {
  Future<List<AppNotification>> fetchNotifications();
  Future<void> markRead(String id);
}
