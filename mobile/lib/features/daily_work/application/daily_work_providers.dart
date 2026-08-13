import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_daily_work_repository.dart';
import '../data/daily_work_repository.dart';
import '../data/mock_daily_work_repository.dart';
import '../domain/daily_work_models.dart';

final dailyWorkRepositoryProvider = Provider<DailyWorkRepository>((ref) {
  if (AppConfig.useMockApi) return MockDailyWorkRepository();
  return ApiDailyWorkRepository(ref.watch(apiClientProvider));
});

enum DailyWorkFilter { today, thisWeek, thisMonth, custom }

final dailyWorkFilterProvider = StateProvider<DailyWorkFilter>((ref) => DailyWorkFilter.today);
final dailyWorkCustomRangeProvider = StateProvider<DailyWorkDateRange?>((ref) => null);

/// Deliberately not Flutter's `DateTimeRange` — keeps this file
/// material-free so it stays cheaply testable, and avoids a name clash for
/// screens that import both.
class DailyWorkDateRange {
  const DailyWorkDateRange({required this.start, required this.end});
  final DateTime start;
  final DateTime end;
}

final dailyWorkEntriesProvider = FutureProvider.autoDispose<List<DailyWorkEntry>>((ref) {
  final filter = ref.watch(dailyWorkFilterProvider);
  final now = DateTime.now();
  DateTime from;
  final to = DateTime(now.year, now.month, now.day, 23, 59, 59);

  switch (filter) {
    case DailyWorkFilter.today:
      from = DateTime(now.year, now.month, now.day);
      break;
    case DailyWorkFilter.thisWeek:
      from = DateTime(now.year, now.month, now.day).subtract(Duration(days: now.weekday - 1));
      break;
    case DailyWorkFilter.thisMonth:
      from = DateTime(now.year, now.month, 1);
      break;
    case DailyWorkFilter.custom:
      final range = ref.watch(dailyWorkCustomRangeProvider);
      from = range?.start ?? DateTime(now.year, now.month, now.day);
      break;
  }
  final effectiveTo = filter == DailyWorkFilter.custom ? (ref.watch(dailyWorkCustomRangeProvider)?.end ?? to) : to;

  return ref.watch(dailyWorkRepositoryProvider).fetchEntries(from: from, to: effectiveTo);
});

class DailyWorkController {
  DailyWorkController(this._ref);
  final Ref _ref;

  Future<void> submit({
    required String title,
    required DailyWorkCategory category,
    required String location,
    required String description,
    required DateTime startTime,
    DateTime? completionTime,
    required List<File> photos,
    String? remarks,
  }) async {
    await _ref.read(dailyWorkRepositoryProvider).createEntry(
          title: title,
          category: category,
          location: location,
          description: description,
          startTime: startTime,
          completionTime: completionTime,
          photos: photos,
          remarks: remarks,
        );
    _ref.invalidate(dailyWorkEntriesProvider);
  }
}

final dailyWorkControllerProvider = Provider((ref) => DailyWorkController(ref));
