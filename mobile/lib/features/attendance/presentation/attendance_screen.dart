import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/location/location_service.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/attendance_providers.dart';
import '../domain/attendance_models.dart';

/// Section 19/20: attendance dashboard with GPS-verified clock in/out.
class AttendanceScreen extends ConsumerStatefulWidget {
  const AttendanceScreen({super.key});

  @override
  ConsumerState<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends ConsumerState<AttendanceScreen> {
  bool _busy = false;

  Future<void> _handleClockAction({required bool clockIn}) async {
    setState(() => _busy = true);
    try {
      final result = clockIn
          ? await ref.read(attendanceControllerProvider).clockIn()
          : await ref.read(attendanceControllerProvider).clockOut();
      if (!mounted) return;
      _showResultDialog(allowed: result.allowed, message: result.message);
    } on LocationPermissionDenied {
      if (mounted) {
        _showResultDialog(
            allowed: false,
            message: 'Location permission is required to clock in/out.');
      }
    } on LocationServiceDisabled {
      if (mounted) {
        _showResultDialog(
            allowed: false,
            message: 'Please turn on Location Services to clock in/out.');
      }
    } catch (e) {
      if (mounted) {
        _showResultDialog(
            allowed: false,
            message: 'Unable to reach CPMSPro. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _showResultDialog({required bool allowed, required String message}) {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        icon: Icon(
          allowed ? Icons.check_circle_rounded : Icons.location_off_rounded,
          color: allowed ? AppColors.success : AppColors.danger,
          size: 40,
        ),
        title: Text(allowed ? 'Location Verified' : 'Unable to Clock In',
            textAlign: TextAlign.center),
        content: Text(message, textAlign: TextAlign.center),
        actions: [
          Center(
              child: TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('OK'))),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final statusAsync = ref.watch(attendanceStatusProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Attendance'),
        actions: [
          IconButton(
            icon: const Icon(Icons.history_rounded),
            onPressed: () => context.push('/attendance/history'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(attendanceStatusProvider),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            statusAsync.when(
              loading: () => const SkeletonCard(height: 220),
              error: (e, _) => AppStateView.error(
                  onRetry: () => ref.invalidate(attendanceStatusProvider)),
              data: (status) => _StatusCard(
                  status: status,
                  busy: _busy,
                  onClockAction: _handleClockAction),
            ),
            const SizedBox(height: 24),
            const SectionHeader(title: 'This Month'),
            _MonthSummaryPreview(),
          ],
        ),
      ),
    );
  }
}

class _StatusCard extends StatelessWidget {
  const _StatusCard(
      {required this.status, required this.busy, required this.onClockAction});

  final AttendanceStatus status;
  final bool busy;
  final Future<void> Function({required bool clockIn}) onClockAction;

  @override
  Widget build(BuildContext context) {
    final isClockedIn = status.status == ClockStatus.clockedIn;
    return AppSectionCard(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.apartment_rounded,
                  size: 18, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: 6),
              Expanded(
                  child: Text(status.propertyName,
                      style: const TextStyle(fontWeight: FontWeight.w700))),
            ],
          ),
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.symmetric(vertical: 24),
            width: double.infinity,
            decoration: BoxDecoration(
              color: (isClockedIn ? AppColors.success : AppColors.textSecondary)
                  .withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(18),
            ),
            child: Column(
              children: [
                Icon(
                    isClockedIn
                        ? Icons.check_circle_rounded
                        : Icons.schedule_rounded,
                    size: 34,
                    color: isClockedIn
                        ? AppColors.success
                        : AppColors.textSecondary),
                const SizedBox(height: 8),
                Text(
                  isClockedIn ? 'CLOCKED IN' : 'NOT CLOCKED IN',
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 18,
                    letterSpacing: 0.5,
                    color: isClockedIn
                        ? AppColors.success
                        : AppColors.textSecondary,
                  ),
                ),
                if (isClockedIn && status.clockInTime != null) ...[
                  const SizedBox(height: 4),
                  Text(DateFormat('h:mm a').format(status.clockInTime!),
                      style: const TextStyle(
                          fontWeight: FontWeight.w700, fontSize: 15)),
                ],
              ],
            ),
          ),
          if (isClockedIn) ...[
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                    child: _StatTile(
                        label: 'Shift', value: status.shiftLabel ?? '—')),
                Expanded(
                    child: _StatTile(
                        label: 'Late',
                        value: status.isLate ? 'Yes' : 'No',
                        valueColor: status.isLate ? AppColors.warning : null)),
              ],
            ),
          ],
          const SizedBox(height: 20),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed:
                  busy ? null : () => onClockAction(clockIn: !isClockedIn),
              style: ElevatedButton.styleFrom(
                backgroundColor: isClockedIn
                    ? AppColors.danger
                    : Theme.of(context).colorScheme.primary,
              ),
              icon: busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white))
                  : Icon(
                      isClockedIn ? Icons.logout_rounded : Icons.login_rounded,
                      size: 20),
              label: Text(isClockedIn ? 'CLOCK OUT' : 'CLOCK IN'),
            ),
          ),
        ],
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({required this.label, required this.value, this.valueColor});
  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(label,
            style:
                const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
        const SizedBox(height: 2),
        Text(value,
            style: TextStyle(fontWeight: FontWeight.w700, color: valueColor)),
      ],
    );
  }
}

class _MonthSummaryPreview extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final now = DateTime.now();
    final summaryAsync = ref
        .watch(attendanceHistoryProvider((year: now.year, month: now.month)));

    return summaryAsync.when(
      loading: () => const SkeletonCard(height: 100),
      error: (_, __) => const SizedBox.shrink(),
      data: (summary) => AppSectionCard(
        child: Row(
          children: [
            Expanded(
                child: _StatTile(
                    label: 'Days Worked', value: '${summary.daysWorked}')),
            Expanded(
                child: _StatTile(
                    label: 'Total Hours',
                    value: summary.totalHours.toStringAsFixed(1))),
            Expanded(
                child:
                    _StatTile(label: 'Late', value: '${summary.lateArrivals}')),
            Expanded(
                child: _StatTile(
                    label: 'OT Hours',
                    value: summary.overtimeHours.toStringAsFixed(1))),
          ],
        ),
      ),
    );
  }
}
