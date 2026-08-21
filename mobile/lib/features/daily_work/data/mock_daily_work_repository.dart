import 'dart:io';

import 'package:uuid/uuid.dart';

import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/daily_work_models.dart';
import 'daily_work_repository.dart';

class MockDailyWorkRepository implements DailyWorkRepository {
  final List<DailyWorkEntry> _entries = MockFixtures.instance.dailyWork;

  @override
  Future<List<DailyWorkEntry>> fetchEntries(
      {DateTime? from, DateTime? to}) async {
    await Future.delayed(const Duration(milliseconds: 400));
    var results = List<DailyWorkEntry>.from(_entries);
    if (from != null) {
      results = results.where((e) => !e.startTime.isBefore(from)).toList();
    }
    if (to != null) {
      results = results.where((e) => !e.startTime.isAfter(to)).toList();
    }
    results.sort((a, b) => b.startTime.compareTo(a.startTime));
    return results;
  }

  @override
  Future<DailyWorkEntry> createEntry({
    required String title,
    required DailyWorkCategory category,
    required String location,
    required String description,
    required DateTime startTime,
    DateTime? completionTime,
    required List<File> photos,
    String? remarks,
  }) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final entry = DailyWorkEntry(
      id: 'DW-${const Uuid().v4().substring(0, 8)}',
      title: title,
      category: category,
      location: location,
      description: description,
      startTime: startTime,
      completionTime: completionTime,
      photoCount: photos.length,
      remarks: remarks,
    );
    _entries.insert(0, entry);
    return entry;
  }
}
