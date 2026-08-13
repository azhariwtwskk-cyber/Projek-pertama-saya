import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/attendance_providers.dart';
import '../domain/attendance_models.dart';

/// Section 21: monthly attendance history with a month picker + summary.
class AttendanceHistoryScreen extends ConsumerStatefulWidget {
  const AttendanceHistoryScreen({super.key});

  @override
  ConsumerState<AttendanceHistoryScreen> createState() => _AttendanceHistoryScreenState();
}

class _AttendanceHistoryScreenState extends ConsumerState<AttendanceHistoryScreen> {
  late DateTime _selectedMonth = DateTime(DateTime.now().year, DateTime.now().month);

  void _shiftMonth(int delta) {
    setState(() => _selectedMonth = DateTime(_selectedMonth.year, _selectedMonth.month + delta));
  }

  @override
  Widget build(BuildContext context) {
    final summaryAsync = ref.watch(attendanceHistoryProvider((year: _selectedMonth.year, month: _selectedMonth.month)));

    return Scaffold(
      appBar: AppBar(title: const Text('Attendance History')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                IconButton(icon: const Icon(Icons.chevron_left_rounded), onPressed: () => _shiftMonth(-1)),
                Text(DateFormat('MMMM yyyy').format(_selectedMonth), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                IconButton(icon: const Icon(Icons.chevron_right_rounded), onPressed: () => _shiftMonth(1)),
              ],
            ),
          ),
          Expanded(
            child: summaryAsync.when(
              loading: () => const Padding(padding: EdgeInsets.all(16), child: SkeletonList(count: 6, itemHeight: 64)),
              error: (e, _) => Center(child: AppStateView.error(onRetry: () => ref.invalidate(attendanceHistoryProvider((year: _selectedMonth.year, month: _selectedMonth.month))))),
              data: (summary) => ListView(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                children: [
                  AppSectionCard(
                    child: Row(
                      children: [
                        Expanded(child: _SummaryStat(label: 'Days Worked', value: '${summary.daysWorked}')),
                        Expanded(child: _SummaryStat(label: 'Total Hours', value: summary.totalHours.toStringAsFixed(1))),
                        Expanded(child: _SummaryStat(label: 'Late', value: '${summary.lateArrivals}')),
                        Expanded(child: _SummaryStat(label: 'OT Hours', value: summary.overtimeHours.toStringAsFixed(1))),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  const SectionHeader(title: 'Daily Records'),
                  if (summary.records.isEmpty)
                    AppStateView.noTasksToday()
                  else
                    ...summary.records.map((r) => _RecordTile(record: r)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SummaryStat extends StatelessWidget {
  const _SummaryStat({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
      ],
    );
  }
}

class _RecordTile extends StatelessWidget {
  const _RecordTile({required this.record});
  final AttendanceRecord record;

  @override
  Widget build(BuildContext context) {
    final worked = record.clockIn != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppSectionCard(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Row(
          children: [
            SizedBox(
              width: 46,
              child: Column(
                children: [
                  Text(DateFormat('d').format(record.date), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  Text(DateFormat('EEE').format(record.date), style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: worked
                  ? Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('In: ${DateFormat('h:mm a').format(record.clockIn!)}', style: const TextStyle(fontSize: 12.5)),
                              if (record.clockOut != null)
                                Text('Out: ${DateFormat('h:mm a').format(record.clockOut!)}', style: const TextStyle(fontSize: 12.5)),
                            ],
                          ),
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text('${record.hoursWorked.toStringAsFixed(1)}h', style: const TextStyle(fontWeight: FontWeight.w700)),
                            if (record.isLate)
                              const Text('Late', style: TextStyle(color: AppColors.warning, fontSize: 11, fontWeight: FontWeight.w600)),
                          ],
                        ),
                      ],
                    )
                  : const Text('Off Day', style: TextStyle(color: AppColors.textSecondary)),
            ),
          ],
        ),
      ),
    );
  }
}
