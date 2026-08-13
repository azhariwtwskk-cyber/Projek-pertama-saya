import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/notification_models.dart';
import 'notifications_repository.dart';

class MockNotificationsRepository implements NotificationsRepository {
  final List<AppNotification> _items = MockFixtures.instance.notifications;

  @override
  Future<List<AppNotification>> fetchNotifications() async {
    await Future.delayed(const Duration(milliseconds: 400));
    final sorted = List<AppNotification>.from(_items)..sort((a, b) => b.createdAt.compareTo(a.createdAt));
    return sorted;
  }

  @override
  Future<void> markRead(String id) async {
    final i = _items.indexWhere((n) => n.id == id);
    if (i != -1) _items[i] = _items[i].copyWith(isRead: true);
  }
}
