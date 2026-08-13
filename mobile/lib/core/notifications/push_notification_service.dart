import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Wraps Firebase Cloud Messaging + local notifications for deep-linked
/// push (section 25). Firebase itself only activates once a real
/// `google-services.json` / `GoogleService-Info.plist` is added to the
/// platform projects and `Firebase.initializeApp()` is called in
/// `main.dart`; until then this degrades gracefully to a no-op so the rest
/// of the app (including the in-app Notification Centre, which is driven
/// by the REST API, not FCM) works standalone.
///
/// Deep link contract: every push payload carries a `route` data field
/// (e.g. `/tasks/WO-2026-0082`) that [onDeepLink] forwards straight to
/// GoRouter, so tapping "New Work Order WO-2026-0082" opens Task Detail
/// directly.
class PushNotificationService {
  PushNotificationService();

  final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();
  final _deepLinkController = StreamController<String>.broadcast();

  Stream<String> get onDeepLink => _deepLinkController.stream;

  Future<void> initialize() async {
    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosInit = DarwinInitializationSettings();
    await _local.initialize(
      const InitializationSettings(android: androidInit, iOS: iosInit),
      onDidReceiveNotificationResponse: (response) {
        final route = response.payload;
        if (route != null && route.isNotEmpty) {
          _deepLinkController.add(route);
        }
      },
    );
  }

  /// Called by FCM's onMessage/onMessageOpenedApp handlers once Firebase is
  /// wired up. Kept as a plain method (not tied to firebase_messaging
  /// types) so it's testable and so this file compiles even before
  /// Firebase is configured for a given environment.
  Future<void> showLocalNotification({
    required int id,
    required String title,
    required String body,
    String? route,
  }) async {
    const androidDetails = AndroidNotificationDetails(
      'cpmspro_default',
      'CPMSPro Notifications',
      channelDescription: 'Work orders, tasks, PM reminders and announcements',
      importance: Importance.high,
      priority: Priority.high,
    );
    const details = NotificationDetails(android: androidDetails, iOS: DarwinNotificationDetails());
    try {
      await _local.show(id, title, body, details, payload: route);
    } catch (e) {
      debugPrint('Failed to show local notification: $e');
    }
  }

  void dispose() => _deepLinkController.close();
}
