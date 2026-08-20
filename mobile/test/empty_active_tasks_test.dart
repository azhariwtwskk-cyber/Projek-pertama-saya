import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:cpmspro_workforce/shared/widgets/empty_state.dart';

/// Regression coverage for the real-device "task disappears after submit"
/// bug: once My Tasks (or Home's Recent Tasks) has zero active items, the
/// empty state must never look like the submitted work vanished — it must
/// say completed work lives in Work Order History and offer a direct way
/// to get there.
void main() {
  testWidgets(
      'AppStateView.noActiveTasks points to Work Order History and its '
      'button navigates there', (tester) async {
    var tapped = false;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: AppStateView.noActiveTasks(
            onViewHistory: () => tapped = true,
          ),
        ),
      ),
    );

    expect(find.text('No Active Tasks'), findsOneWidget);
    expect(
      find.textContaining('Work Order History'),
      findsWidgets,
    );
    final button =
        find.widgetWithText(OutlinedButton, 'View Work Order History');
    expect(button, findsOneWidget);

    await tester.tap(button);
    await tester.pump();

    expect(tapped, isTrue);
  });
}
