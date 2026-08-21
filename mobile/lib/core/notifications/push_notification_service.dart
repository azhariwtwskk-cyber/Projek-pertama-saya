import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Local, on-device notification display only — there is NO Firebase
/// Cloud Messaging wiring in this app. No `Firebase.initializeApp()` is
/// ever called, no `google-services.json`/`GoogleService-Info.plist`
/// ships with the platform projects, and no backend device-registration
/// endpoint exists (see mobile/docs/INTEGRATION_REPAIR_REPORT.md). The
/// real, working notification list is the in-app Notification Centre,
/// driven entirely by `GET notifications.php` — that's what staff should
/// rely on. [showLocalNotification] only fires when *this app itself*
/// calls it (e.g. a foreground reminder), never from a server-pushed
/// message, and should not be presented to staff as "push notifications."
///
/// Deep link contract: [showLocalNotification]'s `route` payload (e.g.
/// `/tasks/WO-2026-0082`) is forwarded straight to GoRouter via
/// [onDeepLink] when the user taps the resulting local notification.
class PushNotificationService {
  PushNotificationService();

  final FlutterLocalNotificationsPlugin _local =
      FlutterLocalNotificationsPlugin();
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
    const details = NotificationDetails(
        android: androidDetails, iOS: DarwinNotificationDetails());
    try {
      await _local.show(id, title, body, details, payload: route);
    } catch (e) {
      debugPrint('Failed to show local notification: $e');
    }
  }

  void dispose() => _deepLinkController.close();
}
