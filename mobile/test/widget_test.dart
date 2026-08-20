import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:cpmspro_workforce/main.dart';

void main() {
  setUpAll(() {
    // `flutter test` runs on the host VM, not a real device, so plugins
    // with no host-VM implementation need to be swapped for a fake here —
    // this is purely a test-harness concern; the app itself already falls
    // back to the login screen if a real device's secure storage read
    // fails (see AuthController._restoreSession).

    // Offline outbox (core/database/app_database.dart) needs a
    // databaseFactory to exist at all outside a real device.
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;

    // No secure storage implementation is registered for the host VM, so
    // every call would otherwise throw MissingPluginException — reply
    // with "no value stored" for reads and no-op for writes.
    const channel = MethodChannel('plugins.it_nomads.com/flutter_secure_storage');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(
      channel,
      (call) async {
        switch (call.method) {
          case 'read':
            return null;
          case 'readAll':
            return <String, String>{};
          case 'containsKey':
            return false;
          default:
            return null;
        }
      },
    );
  });

  testWidgets('App boots to the login screen when unauthenticated', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: CpmsproWorkforceApp()));
    // Session restore is async (reads secure storage); let it settle.
    await tester.pumpAndSettle();

    expect(find.text('CPMSPro Workforce'), findsOneWidget);
    expect(find.text('Secure Login'), findsOneWidget);
  });
}
