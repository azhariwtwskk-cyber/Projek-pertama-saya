import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/config/app_branding.dart';
import 'core/l10n/generated/app_localizations.dart';
import 'core/notifications/push_notification_service.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/application/auth_providers.dart';

final pushNotificationServiceProvider =
    Provider<PushNotificationService>((ref) {
  final service = PushNotificationService();
  service.initialize();
  ref.onDispose(service.dispose);
  return service;
});

/// Route string carried by a tapped push notification's payload (section
/// 25), e.g. "/tasks/WO-2026-0082" for "New Work Order WO-2026-0082".
final pushDeepLinkProvider = StreamProvider<String>((ref) {
  return ref.watch(pushNotificationServiceProvider).onDeepLink;
});

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(child: CpmsproWorkforceApp()));
}

class CpmsproWorkforceApp extends ConsumerWidget {
  const CpmsproWorkforceApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(appRouterProvider);
    final user = ref.watch(currentStaffUserProvider);
    final palette =
        AppPalette.fromBranding(user?.branding ?? AppBranding.fallback());

    // Deep link a tapped push notification straight to its route (section 25).
    ref.listen(pushDeepLinkProvider, (_, next) {
      next.whenData(router.go);
    });

    return MaterialApp.router(
      title: 'CPMSPro Workforce',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(palette),
      darkTheme: AppTheme.dark(palette),
      themeMode: ThemeMode.system,
      routerConfig: router,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
    );
  }
}
