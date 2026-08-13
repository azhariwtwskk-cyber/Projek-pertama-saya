import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_notifications_repository.dart';
import '../data/mock_notifications_repository.dart';
import '../data/notifications_repository.dart';
import '../domain/notification_models.dart';

final notificationsRepositoryProvider = Provider<NotificationsRepository>((ref) {
  if (AppConfig.useMockApi) return MockNotificationsRepository();
  return ApiNotificationsRepository(ref.watch(apiClientProvider));
});

final notificationsProvider = FutureProvider.autoDispose<List<AppNotification>>((ref) {
  return ref.watch(notificationsRepositoryProvider).fetchNotifications();
});

final unreadNotificationCountProvider = Provider.autoDispose<int>((ref) {
  return ref.watch(notificationsProvider).maybeWhen(
        data: (items) => items.where((n) => !n.isRead).length,
        orElse: () => 0,
      );
});

class NotificationsController {
  NotificationsController(this._ref);
  final Ref _ref;

  Future<void> markRead(String id) async {
    await _ref.read(notificationsRepositoryProvider).markRead(id);
    _ref.invalidate(notificationsProvider);
  }
}

final notificationsControllerProvider = Provider((ref) => NotificationsController(ref));
